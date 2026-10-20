<?php // app/src/Import/Epub.php
namespace App\Import;
use Kip\Database;

/** The EPUB reader: hostile ZIPs by default (the pentest's tar-bomb finding
 *  is the precedent), so bounds are enforced in two layers. Declared sizes
 *  come from the central directory and every entry passes them BEFORE any
 *  payload is read: member count, per-entry bytes, archive total. Then
 *  actual bytes accumulate across every read and re-enforce the archive
 *  total, so a lying central directory cannot spend reads it never
 *  declared. getFromIndex() returning false is an error, never an empty
 *  string. inspect() is pure reading (no DB); persistence lives in the
 *  import methods. */
final class Epub
{
    public const MAX_MEMBERS = 4096;
    public const MAX_FILE_BYTES = 16 * 1024 * 1024;  // 16 MiB per entry
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024; // 64 MiB per archive
    public const MAX_CHAPTERS = 512;                 // spine documents per book

    private ?\ZipArchive $zip = null;
    /** @var array<int, array<string, mixed>> the central directory stat list
     *  from the last inspect(), kept so structure passes read entries by
     *  index without re-walking the archive */
    private array $stats = [];
    /** @var array<string, int> entry name => central directory index, built
     *  once per inspect() so lookups never re-scan the stat list */
    private array $indices = [];
    /** actual bytes read since the last inspect(), the accumulated half of
     *  the archive cap */
    private int $actualTotal = 0;

    public function __construct(
        private Database $db,
        private string $uploadsDir,   // cover storage (import half)
    ) {}

    /** Bounds pass: open the archive, walk the central directory once
     *  enforcing the member count, per-entry declared bytes, and the
     *  declared total, then run the structure pass. No entry payload is
     *  read here; a zip that fails any cap is refused without a single
     *  getFromIndex call. */
    public function inspect(string $path): array
    {
        // a directory import inspects many files through one instance
        if ($this->zip !== null) { $this->zip->close(); $this->zip = null; }
        $zip = new \ZipArchive();
        if (($code = $zip->open($path, \ZipArchive::RDONLY)) !== true) {
            throw new \RuntimeException("not a readable zip archive (code {$code})");
        }
        $this->zip = $zip;
        if ($zip->numFiles > self::MAX_MEMBERS) {
            throw new \RuntimeException(sprintf('zip holds %d entries, over the 4096 member cap', $zip->numFiles));
        }
        $this->stats = [];
        $declaredTotal = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException("zip central directory unreadable at index {$i}");
            }
            $this->stats[] = $stat;
            $size = (int) $stat['size'];
            if ($size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf("zip entry '%s' declares %d bytes, over the 16 MiB per-entry cap", $stat['name'], $size));
            }
            $declaredTotal += $size;
            if ($declaredTotal > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException(sprintf('zip entries declare %d bytes total, over the 64 MiB archive cap', $declaredTotal));
            }
        }
        $this->actualTotal = 0;
        $this->indices = [];
        foreach ($this->stats as $i => $stat) {
            $this->indices[$stat['name']] ??= $i; // first entry wins on duplicates
        }
        return $this->readStructure();
    }

    /** One length-capped payload read. MAX_FILE_BYTES + 1 is asked from the
     *  zip so an oversized entry is DETECTED (strlen past the cap) rather
     *  than silently truncated; a false return is a hard error, never an
     *  empty string. Every successful read accumulates actual bytes against
     *  the archive cap, the actual half of the bounds contract. */
    public function readEntry(int $i): string
    {
        if ($this->zip === null) {
            throw new \RuntimeException('readEntry runs only after inspect() opened the archive');
        }
        $data = $this->zip->getFromIndex($i, self::MAX_FILE_BYTES + 1);
        if ($data === false) {
            throw new \RuntimeException(sprintf("zip entry '%s' (index %d) failed to read", $this->stats[$i]['name'] ?? $i, $i));
        }
        if (strlen($data) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(sprintf('zip entry %d read %d actual bytes, over the 16 MiB per-entry cap', $i, strlen($data)));
        }
        $this->actualTotal += strlen($data);
        if ($this->actualTotal > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException(sprintf('zip reads total %d actual bytes, over the 64 MiB archive cap', $this->actualTotal));
        }
        return $data;
    }

    /** The structure pass: container, OPF, metadata, manifest, spine, cover.
     *  Every parsed document is DOCTYPE-free by force (LIBXML_NONET alone
     *  does not stop internal entity expansion, and no EPUB package
     *  document ever legitimately carries a DOCTYPE); every href resolves
     *  against the OPF's own directory per decision 2. Only container.xml
     *  and the OPF are read; chapter payloads stay for the import pass. */
    private function readStructure(): array
    {
        $containerIdx = $this->entryIndex('META-INF/container.xml');
        if ($containerIdx === null) {
            throw new \RuntimeException('not an EPUB: META-INF/container.xml is missing');
        }
        $container = $this->readEntry($containerIdx);
        $this->refuseDoctype('META-INF/container.xml', $container);
        if (!preg_match('~<rootfile[^>]*full-path="([^"]+)"~', $container, $m)) {
            throw new \RuntimeException('no package document: META-INF/container.xml names no rootfile full-path');
        }
        $opfPath = (string) preg_replace('~^\./~', '', $m[1]);
        $opfIdx = $this->entryIndex($opfPath);
        if ($opfIdx === null) {
            throw new \RuntimeException(sprintf("the rootfile full-path is missing from the archive: no entry provides '%s'", $opfPath));
        }
        $opf = $this->readEntry($opfIdx);
        $this->refuseDoctype($opfPath, $opf);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($opf, LIBXML_NONET | LIBXML_NOERROR)) {
            throw new \RuntimeException(sprintf("unreadable package document: '%s' does not parse as XML", $opfPath));
        }
        $opfDir = ($d = dirname($opfPath)) === '.' ? '' : $d;
        $meta = $this->readMetadata($doc);
        $manifest = $this->readManifest($doc, $opfDir);
        return [
            'stats' => $this->stats,
            'opfPath' => $opfPath,
            'title' => $meta['title'],
            'creator' => $meta['creator'],
            'summary' => $meta['summary'],
            'language' => $meta['language'],
            'cover' => $this->findCover($doc, $manifest),
            'spine' => $this->readSpine($doc, $manifest),
            'manifest' => $manifest,
        ];
    }

    /** Central directory index for an archive path, or null when no entry
     *  provides it. */
    private function entryIndex(string $name): ?int
    {
        return $this->indices[$name] ?? null;
    }

    /** A DOCTYPE is refused before any parse: LIBXML_NONET alone does not
     *  stop internal entity expansion, and an EPUB package document never
     *  legitimately carries one. */
    private function refuseDoctype(string $what, string $xml): void
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException(sprintf('refuses DOCTYPE: %s declares a DOCTYPE, which no EPUB package document ever needs', $what));
        }
    }

    /** Decision 2, binding: strip the #fragment, rawurldecode, strip a
     *  leading ./, then prefix the OPF's own directory; dot segments
     *  normalize as they go, and a path that is absolute or walks above the
     *  archive root refuses as an escape. */
    private function resolveHref(string $opfDir, string $href): string
    {
        $href = rawurldecode((string) preg_replace('~#.*~', '', $href));
        $href = (string) preg_replace('~^\./~', '', $href);
        if ($href !== '' && $href[0] === '/') {
            throw new \RuntimeException(sprintf("a manifest href escapes the archive: '%s' is absolute, and no archive path is", $href));
        }
        $segments = [];
        foreach (explode('/', ($opfDir === '' ? '' : $opfDir . '/') . $href) as $seg) {
            if ($seg === '' || $seg === '.') { continue; }
            if ($seg === '..') {
                if ($segments === []) {
                    throw new \RuntimeException(sprintf("a manifest href escapes the archive: '%s' walks above the archive root", $href));
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return implode('/', $segments);
    }

    /** First dc:title, first dc:creator, dc:description stripped to plain
     *  text (tags off, entities decoded, whitespace collapsed, 1000 chars),
     *  dc:language lowercased. An absent field is an empty string; refusal
     *  is not metadata's job. */
    private function readMetadata(\DOMDocument $doc): array
    {
        $summary = html_entity_decode(strip_tags($this->firstDc($doc, 'description')?->textContent ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $summary = trim((string) preg_replace('~\s+~u', ' ', $summary));
        if (mb_strlen($summary) > 1000) { $summary = mb_substr($summary, 0, 1000); }
        return [
            'title' => $this->firstDc($doc, 'title')?->textContent ?? '',
            'creator' => $this->firstDc($doc, 'creator')?->textContent ?? '',
            'summary' => $summary,
            'language' => mb_strtolower($this->firstDc($doc, 'language')?->textContent ?? ''),
        ];
    }

    /** The first Dublin Core element of $local, namespace-aware with a
     *  literal-prefix fallback, so a package that binds dc under another
     *  prefix still reads. */
    private function firstDc(\DOMDocument $doc, string $local): ?\DOMElement
    {
        $node = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', $local)->item(0)
            ?? $doc->getElementsByTagName('dc:' . $local)->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    /** Manifest items keyed by id: resolved archive path (decision 2),
     *  media-type, and the space-separated properties list. An escape is
     *  refused the moment any href resolves, wherever it sits. */
    private function readManifest(\DOMDocument $doc, string $opfDir): array
    {
        $manifest = [];
        foreach ($doc->getElementsByTagName('item') as $item) {
            $id = $item->getAttribute('id');
            $href = $item->getAttribute('href');
            if ($id === '' || $href === '') { continue; }
            $manifest[$id] = [
                'path' => $this->resolveHref($opfDir, $href),
                'mediaType' => $item->getAttribute('media-type'),
                'properties' => preg_split('~\s+~', trim($item->getAttribute('properties')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ];
        }
        return $manifest;
    }

    /** Spine idrefs in document order, filtered to xhtml media-types and
     *  capped at MAX_CHAPTERS (decision 3: an over-cap spine refuses before
     *  any chapter payload is read). Every spine path must name a real
     *  entry; a spine with nothing readable refuses the file. */
    private function readSpine(\DOMDocument $doc, array $manifest): array
    {
        $spine = [];
        foreach ($doc->getElementsByTagName('itemref') as $ref) {
            $item = $manifest[$ref->getAttribute('idref')] ?? null;
            if ($item === null || !in_array($item['mediaType'], ['application/xhtml+xml', 'text/html'], true)) {
                continue;
            }
            $spine[] = $item['path'];
        }
        if (count($spine) > self::MAX_CHAPTERS) {
            throw new \RuntimeException(sprintf('spine exceeds 512 chapters: %d readable itemrefs, over the cap', count($spine)));
        }
        foreach ($spine as $path) {
            if ($this->entryIndex($path) === null) {
                throw new \RuntimeException(sprintf("a spine href is missing from the archive: no entry provides '%s'", $path));
            }
        }
        if ($spine === []) {
            throw new \RuntimeException('no readable chapters: the spine holds no xhtml documents');
        }
        return $spine;
    }

    /** Cover resolution: the EPUB 3 properties="cover-image" convention
     *  first, then the EPUB 2 <meta name="cover" content="itemid">
     *  convention. No convention, no cover: null is a clean answer. */
    private function findCover(\DOMDocument $doc, array $manifest): ?string
    {
        foreach ($manifest as $item) {
            if (in_array('cover-image', $item['properties'], true)) {
                return $item['path'];
            }
        }
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if ($meta->getAttribute('name') === 'cover' && isset($manifest[$meta->getAttribute('content')])) {
                return $manifest[$meta->getAttribute('content')]['path'];
            }
        }
        return null;
    }
}
