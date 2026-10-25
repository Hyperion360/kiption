<?php // app/src/Import/WxrReader.php
namespace App\Import;

/** Streaming WordPress eXtended RSS reader: the export every WordPress
 *  admin produces from the browser (Tools > Export, All content), and the
 *  only vehicle that carries the four Fictioneer post types, their
 *  fictioneer_* meta, the fcn_* taxonomy terms, and comments across every
 *  public Fictioneer release. XMLReader walks the file; each <item>
 *  expands to one DOM subtree, so per-item memory is the item's own size.
 *  Hostile-file bounds follow the Epub importer's stance: this is
 *  operator-chosen CLI input, but accidents (a 2 GiB export, a truncated
 *  download) must fail clean. WXR namespace URIs are matched by prefix
 *  (http://wordpress.org/export/) so 1.0/1.1/1.2 exports all parse. */
final class WxrReader
{
    public const MAX_ITEMS = 100000;
    public const MAX_ITEM_BYTES = 8 * 1024 * 1024; // one chapter body, generously
    public const MAX_TOTAL_BYTES = 1 << 30;        // 1 GiB of item payload

    public int $totalBytes = 0;
    /** @var array<string, int> items seen per wp:post_type, the verify half of the report */
    public array $typeCounts = [];

    private const WP_PREFIX = 'http://wordpress.org/export/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    private \XMLReader $xml;
    private string $path;
    private string $stream;
    /** @var array<int, array{login: string, email: string, display_name: string}> wp:author records from the channel, keyed by wp:author_id */
    private array $authors = [];
    private string $siteHost = '';
    private bool $channelScanned = false;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->stream = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path;
        libxml_use_internal_errors(true);
        $this->open();
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The channel's wp:author records (id => login/email/display_name).
     *  Available after the first items() pass began (the channel precedes
     *  the items), and from construction onward if authors() is called
     *  first: it scans the channel itself. */
    public function authors(): array
    {
        if (!$this->channelScanned) { $this->open(); $this->scanChannel(); }
        return $this->authors;
    }

    /** Host of wp:base_site_url (falls back to the channel link), the
     *  import_map namespace that keeps two WordPress sites apart. */
    public function siteHost(): string
    {
        if (!$this->channelScanned) { $this->open(); $this->scanChannel(); }
        return $this->siteHost !== '' ? $this->siteHost : 'unknown-site';
    }

    /** Every items() call re-opens the file: the orchestrator streams the
     *  document once per pass (users, taxonomy, stories, chapters, ...), the
     *  BundleReader idiom, and an XMLReader cannot rewind. */
    private function open(): void
    {
        if (isset($this->xml)) { $this->xml->close(); }
        libxml_clear_errors(); // a failed pass must not poison the next one's first item
        $this->xml = new \XMLReader();
        if (!$this->xml->open($this->stream, null, LIBXML_NONET)) {
            throw new \RuntimeException("not a readable XML file: {$this->path}");
        }
        $this->channelScanned = false;
    }

    /** @return \Generator<int, array> item shapes, in file order */
    public function items(): \Generator
    {
        $this->open();
        $this->typeCounts = []; // per pass: verify() reads the last pass, every pass sees every item
        $this->totalBytes = 0;
        $this->scanChannel(); // cursor ON the first <item>, or at the end
        $count = 0;
        while ($this->xml->nodeType !== \XMLReader::NONE) {
            if ($this->xml->nodeType === \XMLReader::ELEMENT && $this->xml->localName === 'item') {
                if (++$count > self::MAX_ITEMS) {
                    throw new \RuntimeException(sprintf('WXR holds over %d items, over the item-count cap', self::MAX_ITEMS));
                }
                // the cursor stays ON <item>; @ because a truncated subtree makes
                // PHP 8.4+ raise an E_WARNING before expand() returns false, and
                // false is already the handled hard-error path (house @ idiom)
                $node = @$this->xml->expand();
                if ($node === false) throw new \RuntimeException('unreadable item: the WXR is truncated or malformed');
                $moved = $this->xml->next(); // to the following sibling, skipping the item subtree
                yield $this->readItem($node);
                if (!$moved) break;
                continue;
            }
            // whitespace, a comment, or a non-item element in the channel tail: step over it
            $stepped = $this->xml->nodeType === \XMLReader::ELEMENT ? $this->xml->next() : $this->xml->read();
            if (!$stepped) break;
        }
    }

    /** Header check plus the channel walk: refuses DOCTYPE and non-rss roots,
     *  collects wp:author records and wp:base_site_url, and stops on the
     *  first <item> element (cursor left ON it). Manual advance: after an
     *  expand()+next() the cursor already sits on the next sibling, so the
     *  loop must not read() past it. */
    private function scanChannel(): void
    {
        $sawRoot = false;
        $advance = true;
        while (true) {
            if ($advance && !$this->xml->read()) break;
            $advance = true;
            if ($this->xml->nodeType === \XMLReader::DOC_TYPE) {
                throw new \RuntimeException('refuses DOCTYPE: WXR files never declare one (the Epub importer stance)');
            }
            if ($this->xml->nodeType !== \XMLReader::ELEMENT) continue;
            if (!$sawRoot) {
                if ($this->xml->localName !== 'rss') throw new \RuntimeException('not a WXR file: the root element is not rss');
                $sawRoot = true;
                continue;
            }
            $ns = (string) $this->xml->namespaceURI;
            if ($this->xml->localName === 'item') { $this->channelScanned = true; return; }
            if (str_starts_with($ns, self::WP_PREFIX) && $this->xml->localName === 'author') {
                $node = @$this->xml->expand(); // @: see items(), same false-is-an-error path
                if ($node !== false) {
                    $id = (int) ($this->wpChild($node, 'author_id') ?? 0);
                    if ($id > 0) {
                        $this->authors[$id] = [
                            'login' => trim((string) ($this->wpChild($node, 'author_login') ?? '')),
                            'email' => trim((string) ($this->wpChild($node, 'author_email') ?? '')),
                            'display_name' => trim((string) ($this->wpChild($node, 'author_display_name') ?? '')),
                        ];
                    }
                }
                $advance = false; // next() positions the cursor itself
                if (!$this->xml->next()) break;
                continue;
            }
            if (str_starts_with($ns, self::WP_PREFIX) && $this->xml->localName === 'base_site_url' && $this->siteHost === '') {
                $host = parse_url(trim($this->xml->readString()), PHP_URL_HOST);
                if (is_string($host) && $host !== '') $this->siteHost = strtolower($host);
            }
        }
        if (!$sawRoot) throw new \RuntimeException('not a WXR file: empty document');
        if (libxml_get_errors() !== []) {
            // a post-root exit here is either EOF or truncation; only libxml
            // knows which, so a buffered error means the file ends mid-document
            $lines = array_map(static fn(\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \RuntimeException('unreadable WXR: the file ends mid-document (' . implode('; ', $lines) . ')');
        }
        $this->channelScanned = true; // no items at all: a valid, empty export
    }

    private function readItem(\DOMNode $node): array
    {
        if (libxml_get_errors() !== []) {
            $lines = array_map(static fn(\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new \RuntimeException('unreadable item: the WXR is truncated or malformed (' . implode('; ', $lines) . ')');
        }
        $type = (string) ($this->wpChild($node, 'post_type') ?? '');
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;

        $meta = [];
        $comments = [];
        $terms = [];
        $payload = 0;
        foreach ($node->childNodes as $c) {
            if (!$c instanceof \DOMElement) continue; // block comments and text nodes carry nothing
            $ns = (string) $c->namespaceURI;
            $local = $c->localName;
            if (str_starts_with($ns, self::WP_PREFIX)) {
                if ($local === 'postmeta') {
                    $key = $this->wpChild($c, 'meta_key');
                    $value = $this->wpChild($c, 'meta_value');
                    if ($key !== null && $key !== '') $meta[$key] = (string) $value;
                } elseif ($local === 'comment') {
                    $comments[] = $this->readComment($c);
                }
            } elseif ($local === 'category') {
                $terms[] = [
                    'domain' => (string) $c->getAttribute('domain'),
                    'nicename' => (string) $c->getAttribute('nicename'),
                    'name' => $c->textContent,
                ];
            }
        }
        $payload += strlen((string) $this->wpChild($node, 'post_password'));
        $content = (string) ($this->nsChild($node, self::CONTENT_NS, 'encoded') ?? '');
        $payload += strlen($content);
        $payload += array_sum(array_map('strlen', $meta));
        foreach ($comments as $cm) { $payload += strlen($cm['content']); }
        $this->totalBytes += $payload;
        if ($payload > self::MAX_ITEM_BYTES) {
            throw new \RuntimeException(sprintf('item %d carries %d bytes, over the item cap', (int) ($this->wpChild($node, 'post_id') ?? 0), $payload));
        }
        if ($this->totalBytes > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException('item payload total is over the 1 GiB cap: split the export or import per-era files');
        }

        return [
            'type' => $type,
            'id' => (int) ($this->wpChild($node, 'post_id') ?? 0),
            'parent' => (int) ($this->wpChild($node, 'post_parent') ?? 0), // 5.26.0+: the theme's own getter prefers this link
            'login' => trim((string) ($this->nsChild($node, self::DC_NS, 'creator') ?? '')),
            'title' => $this->firstText($node, 'title'),
            'slug' => (string) ($this->wpChild($node, 'post_name') ?? ''),
            'status' => (string) ($this->wpChild($node, 'status') ?? ''),
            'password' => (string) ($this->wpChild($node, 'post_password') ?? ''),
            'date' => (string) ($this->wpChild($node, 'post_date_gmt') ?? ''),
            'modified' => (string) ($this->wpChild($node, 'post_modified_gmt') ?? ''),
            'content' => $content,
            'excerpt' => $this->excerptChild($node),
            'meta' => $meta,
            'terms' => $terms,
            'comments' => $comments,
        ];
    }

    private function readComment(\DOMElement $c): array
    {
        $meta = [];
        foreach ($c->childNodes as $sub) {
            if ($sub instanceof \DOMElement && str_starts_with((string) $sub->namespaceURI, self::WP_PREFIX) && $sub->localName === 'commentmeta') {
                $k = $this->wpChild($sub, 'meta_key');
                if ($k !== null && $k !== '') $meta[$k] = (string) $this->wpChild($sub, 'meta_value');
            }
        }
        return [
            'id' => (string) ($this->wpChild($c, 'comment_id') ?? ''),
            'author' => trim((string) ($this->wpChild($c, 'comment_author') ?? '')),
            'email' => trim((string) ($this->wpChild($c, 'comment_author_email') ?? '')),
            'date' => (string) ($this->wpChild($c, 'comment_date_gmt') ?? ''),
            'content' => (string) ($this->wpChild($c, 'comment_content') ?? ''),
            'approved' => (string) ($this->wpChild($c, 'comment_approved') ?? '0'),
            'type' => (string) ($this->wpChild($c, 'comment_type') ?? ''),
            'meta' => $meta,
        ];
    }

    private function wpChild(\DOMNode $n, string $local): ?string
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && str_starts_with((string) $c->namespaceURI, self::WP_PREFIX) && $c->localName === $local) {
                return $c->textContent;
            }
        }
        return null;
    }

    /** excerpt:encoded rides the WXR namespace on 1.2 exports (older exports omit it). */
    private function excerptChild(\DOMNode $n): string
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === 'encoded'
                && str_starts_with((string) $c->namespaceURI, self::WP_PREFIX)) {
                return $c->textContent;
            }
        }
        return '';
    }

    private function nsChild(\DOMNode $n, string $ns, string $local): ?string
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->namespaceURI === $ns && $c->localName === $local) {
                return $c->textContent;
            }
        }
        return null;
    }

    private function firstText(\DOMNode $n, string $local): string
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->localName === $local && (string) $c->namespaceURI === '') {
                return $c->textContent;
            }
        }
        return '';
    }
}
