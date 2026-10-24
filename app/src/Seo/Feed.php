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

    /** One Atom document. $feedPath is the feed's own address: it is both the
     *  <id> and the rel=self link, because Atom's id must identify THIS feed
     *  (every feed on the site once shared the site root as its id, so a
     *  reader keying on id could not tell the fantasy feed from the archive).
     *  Both paths are required: a default would silently mint the main
     *  feed's identity for a caller that forgets them. $alternatePath is the
     *  feed's human-facing page. Entry ids are story URLs and never change:
     *  readers key entries on those. */
    public static function atom(string $siteName, string $baseUrl, array $stories, bool $fullText,
        string $feedPath, string $alternatePath): string
    {
        $self = $baseUrl . $feedPath;
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
            . '  <id>' . self::e($self) . "</id>\n"
            . '  <link rel="self" href="' . self::e($self) . '" type="application/atom+xml"/>' . "\n"
            . '  <link rel="alternate" href="' . self::e($baseUrl . $alternatePath) . '" type="text/html"/>' . "\n"
            . '  <updated>' . self::e($updated) . "</updated>\n"
            . $items . "</feed>\n";
    }

    /** RSS 2.0. The atom:link rel=self is RSS best practice's form of the same
     *  identity (the RSS advisory recommends it; readers use it to pin the
     *  feed's canonical address after a redirect). /rss is the one RSS
     *  address the app has, so it is written here and callers pass nothing. */
    public static function rss(string $siteName, string $baseUrl, array $stories): string
    {
        $self = $baseUrl . '/rss';
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
            . "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n  <channel>\n"
            . '    <title>' . self::e($siteName) . "</title>\n"
            . '    <link>' . self::e($baseUrl) . "</link>\n"
            . '    <description>Recently updated stories</description>' . "\n"
            . '    <atom:link rel="self" href="' . self::e($self) . '" type="application/rss+xml"/>' . "\n"
            . $items . "  </channel>\n</rss>\n";
    }
}
