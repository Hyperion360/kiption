<?php // app/src/Import/HtmlToMarkdown.php
namespace App\Import;

/** DOMDocument-based converter for the legacy prose tag set. Output feeds the
 *  same App\Markdown render pipeline as new submissions: unknown tags collapse
 *  to text, script/style bodies vanish, javascript: URLs drop, entities decode. */
final class HtmlToMarkdown
{
    /** WordPress prose: strips the wrapper shortcodes post_content carries,
     *  then runs the same converter. Known wrappers lose their brackets and
     *  keep their content (caption keeps image+text, fcnt keeps the note
     *  inline in parentheses); known void shortcodes (gallery, embeds,
     *  Fictioneer's own seventeen) vanish. Unknown shortcodes stay literal
     *  text: mangling an author's [sic] is worse than a leftover bracket. */
    public static function wordpress(string $html): string
    {
        $html = preg_replace('/\[(?:wp_)?caption[^\]]*\]/i', '', $html) ?? $html;
        $html = preg_replace('/\[\/(?:wp_)?caption\]/i', '', $html) ?? $html;
        $html = preg_replace('/\[fcnt\](.*?)\[\/fcnt\]/is', ' ($1)', $html) ?? $html;
        $html = preg_replace('/\[(?:gallery|embed|audio|video|playlist|contact-form|fictioneer_[a-z_]+)[^\]]*\]/i', '', $html) ?? $html;
        return self::convert($html);
    }

    public static function convert(string $html): string
    {
        $html = '<?xml encoding="utf-8"?><body>' . $html . '</body>';
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        libxml_clear_errors();
        $body = $doc->getElementsByTagName('body')->item(0);
        $out = self::nodes($body);
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
        return trim($out) . "\n";
    }

    private static function nodes(?\DOMNode $node): string
    {
        $s = '';
        for ($child = $node?->firstChild; $child !== null; $child = $child->nextSibling) {
            $s .= self::node($child);
        }
        return $s;
    }

    private static function node(\DOMNode $n): string
    {
        if ($n instanceof \DOMText) return str_replace(["\xc2\xa0"], [' '], $n->wholeText);
        if (!$n instanceof \DOMElement) return '';
        $tag = strtolower($n->tagName);
        $inner = self::nodes($n);
        return match ($tag) {
            'p' => trim($inner) . "\n\n",
            'br' => "  \n",
            'i', 'em' => $inner === '' ? '' : '*' . trim($inner) . '*',
            'b', 'strong' => $inner === '' ? '' : '**' . trim($inner) . '**',
            'u' => $inner === '' ? '' : '_' . trim($inner) . '_',
            'hr' => "\n\n---\n",
            'blockquote' => $inner === '' ? '' : '> ' . str_replace("\n", "\n> ", trim($inner)) . "\n\n",
            'a' => self::safeUrl($n->getAttribute('href')) === ''
                ? $inner
                : '[' . trim($inner) . '](' . self::safeUrl($n->getAttribute('href')) . ')',
            'img' => self::safeUrl($n->getAttribute('src')) === ''
                ? ''
                : '![' . trim($n->getAttribute('alt')) . '](' . self::safeUrl($n->getAttribute('src')) . ')',
            'script', 'style' => '',
            'li' => '- ' . trim($inner) . "\n",
            'ul', 'ol' => $inner,
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $inner === '' ? '' : '**' . trim($inner) . "**\n\n",
            'del', 's', 'strike' => $inner,
            'sup', 'sub' => $inner,
            'pre' => trim($inner) . "\n\n",
            'table' => self::tableText($n),
            default => $inner,
        };
    }

    private static function safeUrl(string $url): string
    {
        return preg_match('#^https?://#i', trim($url)) ? trim($url) : '';
    }

    /** Markdown has no table syntax this flavor renders, so a table becomes
     *  pipe-joined cell lines in one paragraph: readable, honest, lossy. */
    private static function tableText(\DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', self::nodes($cell)) ?? '');
                }
            }
            if ($cells !== []) $rows[] = implode(' | ', $cells);
        }
        return $rows === [] ? '' : implode("  \n", $rows) . "\n\n";
    }
}
