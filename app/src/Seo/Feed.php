<?php // app/src/Seo/Feed.php
namespace App\Seo;

final class Feed
{
    private const X = ENT_XML1 | ENT_COMPAT;

    private static function e(?string $s): string
    {
        // XML 1.0 admits only tab, LF and CR among C0 controls; any other control
        // byte in a title or summary makes readers reject the whole feed, and
        // htmlspecialchars strips none of them. Strip first, then escape.
        $s = preg_replace('#[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}]#u', '', (string) $s) ?? '';
        return htmlspecialchars($s, self::X, 'UTF-8');
    }

    public static function atom(string $siteName, string $baseUrl, array $stories, bool $fullText = false): string
    {
        $items = '';
        foreach ($stories as $s) {
            $url = $baseUrl . '/story/view/' . rawurlencode($s['slug']);
            $items .= "  <entry>\n"
                . '    <title>' . self::e($s['title']) . "</title>\n"
                . '    <link href="' . self::e($url) . "\"/>\n"
                . '    <id>' . self::e($url) . "</id>\n"
                . '    <updated>' . self::e($s['updated_at']) . "</updated>\n"
                . '    <published>' . self::e($s['created_at']) . "</published>\n"
                . '    <author><name>' . self::e($s['penname']) . "</name></author>\n"
                . '    <summary>' . self::e($s['summary']) . "</summary>\n"
                // Full-text mode: the first validated chapter, rendered from its
                // markdown-at-rest source, then the single Feed::e pass. Atom's
                // content default is text, so the element must declare type="html";
                // one escape over already-rendered HTML round-trips exactly once
                // under that type (finding 9). Summary mode emits nothing here.
                . ($fullText
                    ? '    <content type="html">' . self::e(\App\Markdown::render((string) ($s['first_chapter'] ?? ''))) . "</content>\n"
                    : '')
                . "  </entry>\n";
        }
        $updated = $stories[0]['updated_at'] ?? date('c');
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<feed xmlns=\"http://www.w3.org/2005/Atom\">\n"
            . '  <title>' . self::e($siteName) . "</title>\n"
            . '  <id>' . self::e($baseUrl . '/') . "</id>\n"
            . '  <link href="' . self::e($baseUrl) . "\"/>\n"
            . '  <updated>' . self::e($updated) . "</updated>\n"
            . $items . "</feed>\n";
    }

    public static function rss(string $siteName, string $baseUrl, array $stories): string
    {
        $items = '';
        foreach ($stories as $s) {
            $url = $baseUrl . '/story/view/' . rawurlencode($s['slug']);
            $items .= "    <item>\n"
                . '      <title>' . self::e($s['title']) . "</title>\n"
                . '      <link>' . self::e($url) . "</link>\n"
                . '      <guid>' . self::e($url) . "</guid>\n"
                . '      <pubDate>' . self::e(date('r', strtotime((string) $s['updated_at']))) . "</pubDate>\n"
                . '      <description>' . self::e($s['summary']) . "</description>\n"
                . "    </item>\n";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . "<rss version=\"2.0\">\n  <channel>\n"
            . '    <title>' . self::e($siteName) . "</title>\n"
            . '    <link>' . self::e($baseUrl) . "</link>\n"
            . '    <description>Recently updated stories</description>' . "\n"
            . $items . "  </channel>\n</rss>\n";
    }
}
