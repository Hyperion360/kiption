<?php // app/src/Import/HtmlToMarkdown.php
namespace App\Import;

/** DOMDocument-based converter for the legacy prose tag set. Output feeds the
 *  same App\Markdown render pipeline as new submissions: unknown tags collapse
 *  to text, script/style bodies vanish, javascript: URLs drop, entities decode. */
final class HtmlToMarkdown
{
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
            default => $inner,
        };
    }

    private static function safeUrl(string $url): string
    {
        return preg_match('#^https?://#i', trim($url)) ? trim($url) : '';
    }
}
