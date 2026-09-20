<?php // app/src/Export/Html.php
namespace App\Export;

/** The standalone whole-work HTML export: one self-contained document with
 *  the doctype, charset, a small metadata block (title, byline, rating,
 *  word count, summary, source link), and every chapter's prose through
 *  Markdown::render UNPATCHED - the void-tag close is an EPUB-only concern
 *  (finding 3); this file is HTML5 and keeps the renderer's output exactly
 *  as the site views emit it. */
final class Html
{
    /** @param array<string,mixed> $story a wholeWork row
     *  @param array{position:int,title:string,content:string,word_count:int}[] $chapters */
    public function build(array $story, array $chapters, string $baseUrl): string
    {
        $title = self::e((string) $story['title']);
        $language = preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($story['language'] ?? '')) === 1
            ? (string) $story['language'] : 'en';
        $url = rtrim($baseUrl, '/') . '/story/view/' . (string) $story['slug'];
        $out = "<!DOCTYPE html>\n<html lang=\"" . self::e($language) . "\">\n<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . '<title>' . $title . ' by ' . self::e((string) $story['penname']) . "</title>\n"
            . "</head>\n<body>\n<header>\n"
            . '<h1>' . $title . "</h1>\n"
            . '<p>by ' . self::e((string) $story['penname'])
            . ' | ' . self::e((string) $story['rating_label'])
            . ' | ' . number_format((int) $story['word_count']) . " words</p>\n"
            . '<p>' . self::e((string) $story['summary']) . "</p>\n"
            . '<p>Exported from <a href="' . self::e($url) . '">' . self::e($url) . '</a></p>' . "\n"
            . "</header>\n";
        foreach ($chapters as $c) {
            $n = (int) $c['position'];
            $chapterTitle = (string) $c['title'] !== '' ? (string) $c['title'] : 'Chapter ' . $n;
            $out .= '<h2 id="ch-' . $n . '">' . self::e($chapterTitle) . "</h2>\n"
                . \App\Markdown::render((string) $c['content']);
        }
        return $out . "</body>\n</html>\n";
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
