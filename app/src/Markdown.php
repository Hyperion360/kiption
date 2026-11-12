<?php // app/src/Markdown.php
namespace App;

final class Markdown
{
    /** Prose subset. Raw HTML does not exist in this flavor: every non-marker
     *  character is escaped, so stored content cannot carry markup. */
    public static function render(string $md, bool $blocks = false): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $md = trim($md);
        if ($md === '') return '';
        $out = '';
        foreach (preg_split('/\n{2,}/', $md) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') continue;
            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $block)) {
                $out .= "<hr>\n";
                continue;
            }
            $lines = explode("\n", $block);
            // Owner messages only ($blocks): headings and lists. Never on story
            // text, where a line starting "- " is dialogue, not a list.
            if ($blocks && ($html = self::structured($lines)) !== null) {
                $out .= $html;
                continue;
            }
            if ($lines[0] !== '' && $lines[0][0] === '>') {
                $quoted = implode("\n", array_map(static fn(string $l): string => preg_replace('/^>\s?/', '', $l) ?? '', $lines));
                $out .= '<blockquote>' . rtrim(self::inline($quoted)) . "</blockquote>\n";
                continue;
            }
            $out .= self::inline($block);
        }
        return $out;
    }

    /** One block as a heading or a bullet or numbered list, else null.
     *  @param list<string> $lines */
    private static function structured(array $lines): ?string
    {
        if (count($lines) === 1 && preg_match('/^(#{1,3})\s+(.+)$/', $lines[0], $m) === 1) {
            $tag = 'h' . (strlen($m[1]) + 1);
            return '<' . $tag . '>' . self::inlineText($m[2]) . '</' . $tag . ">\n";
        }
        foreach (['ul' => '/^[-*]\s+(.*)$/', 'ol' => '/^\d+[.)]\s+(.*)$/'] as $tag => $re) {
            $items = [];
            foreach ($lines as $l) {
                if (preg_match($re, $l, $m) !== 1) {
                    $items = [];
                    break;
                }
                $items[] = '<li>' . self::inlineText($m[1]) . '</li>';
            }
            if ($items !== []) return '<' . $tag . ">\n" . implode("\n", $items) . '</' . $tag . ">\n";
        }
        return null;
    }

    /** Escape + apply inline markers to one paragraph's text; wraps in <p>. */
    private static function inline(string $text): string
    {
        return '<p>' . self::inlineText($text) . "</p>\n";
    }

    private static function inlineText(string $text): string
    {
        // Protect the link/image constructs, then escape everything, then
        // re-materialize them: this ordering is what makes raw HTML impossible.
        $tokens = [];
        $text = preg_replace_callback(
            '/(!?)\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/',
            static function (array $m) use (&$tokens): string {
                $tokens[] = $m;
                return "\x00" . (count($tokens) - 1) . "\x00";
            },
            $text
        ) ?? $text;
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        foreach ($tokens as $i => $m) {
            $label = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $url = htmlspecialchars($m[3], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            $replacement = $m[1] === '!'
                ? '<img src="' . $url . '" alt="' . $label . '">'
                : (str_starts_with($m[3], 'https://')
                    ? '<a href="' . $url . '" rel="noopener">' . $label . '</a>'
                    : '[' . $label . '](' . $url . ')');
            $safe = str_replace("\x00{$i}\x00", $replacement, $safe);
        }
        $safe = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $safe) ?? $safe;
        $safe = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $safe) ?? $safe;
        $safe = preg_replace('/(?<!_)_([^_\n]+)_(?!_)/', '<em>$1</em>', $safe) ?? $safe;
        return $safe;
    }

    /** Words in the markdown source, markup syntax and scene breaks excluded. */
    public static function wordCount(string $md): int
    {
        $t = str_replace(["\r\n", "\r"], "\n", $md);
        $t = preg_replace('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/m', ' ', $t) ?? $t;
        $t = preg_replace('/(!?)\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/', '$2', $t) ?? $t;
        $t = str_replace(['*', '_', '`'], ' ', $t);
        return str_word_count(trim($t));
    }
}
