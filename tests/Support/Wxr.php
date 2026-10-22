<?php // tests/Support/Wxr.php
namespace App\Tests\Support;

/** Builds WXR strings for the Fictioneer importer tests: channel wrapper,
 *  item serialization with postmeta/terms/comments, and era presets. */
final class Wxr
{
    /** @param list<string> $items
     *  @param list<array{id:int, login:string, email?:string, display_name?:string}> $authors channel wp:author records */
    public static function channel(array $items, array $authors = [], string $siteUrl = 'https://old.example.org/'): string
    {
        $head = '<wp:base_site_url>' . htmlspecialchars($siteUrl, ENT_XML1) . '</wp:base_site_url>';
        foreach ($authors as $a) {
            $head .= '<wp:author><wp:author_id>' . (int) $a['id'] . '</wp:author_id>'
                . '<wp:author_login><![CDATA[' . $a['login'] . ']]></wp:author_login>'
                . '<wp:author_email><![CDATA[' . ($a['email'] ?? '') . ']]></wp:author_email>'
                . '<wp:author_display_name><![CDATA[' . ($a['display_name'] ?? '') . ']]></wp:author_display_name></wp:author>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"'
            . ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
            . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:wp="http://wordpress.org/export/1.2/">'
            . '<channel>' . $head . implode('', $items) . '</channel></rss>';
    }

    /** @param array<string, mixed> $i */
    public static function item(array $i = []): string
    {
        $id = (string) ($i['id'] ?? 1);
        $s = '<item>'
            . '<title>' . htmlspecialchars((string) ($i['title'] ?? 'Chapter ' . $id), ENT_XML1) . '</title>'
            . '<dc:creator><![CDATA[' . ($i['login'] ?? 'anna') . ']]></dc:creator>'
            . '<content:encoded><![CDATA[' . ($i['content'] ?? '<p>Chapter text.</p>') . ']]></content:encoded>'
            . '<excerpt:encoded><![CDATA[' . ($i['excerpt'] ?? '') . ']]></excerpt:encoded>'
            . '<wp:post_id>' . $id . '</wp:post_id>'
            . '<wp:post_date_gmt>' . ($i['date'] ?? '2024-01-01 00:00:00') . '</wp:post_date_gmt>'
            . '<wp:post_modified_gmt>' . ($i['modified'] ?? ($i['date'] ?? '2024-01-01 00:00:00')) . '</wp:post_modified_gmt>'
            . '<wp:status>' . ($i['status'] ?? 'publish') . '</wp:status>'
            . '<wp:post_name>' . ($i['slug'] ?? 'slug-' . $id) . '</wp:post_name>'
            . '<wp:post_parent>' . ($i['parent'] ?? 0) . '</wp:post_parent>'
            . '<wp:post_type>' . ($i['type'] ?? 'fcn_chapter') . '</wp:post_type>'
            . '<wp:post_password>' . ($i['password'] ?? '') . '</wp:post_password>';
        foreach (($i['meta'] ?? []) as $k => $v) {
            $s .= '<wp:postmeta><wp:meta_key>' . htmlspecialchars((string) $k, ENT_XML1) . '</wp:meta_key>'
                . '<wp:meta_value>' . htmlspecialchars((string) $v, ENT_XML1) . '</wp:meta_value></wp:postmeta>';
        }
        foreach (($i['terms'] ?? []) as $t) {
            $s .= '<category domain="' . $t['domain'] . '" nicename="' . $t['nicename'] . '"><![CDATA[' . $t['name'] . ']]></category>';
        }
        foreach (($i['comments'] ?? []) as $n => $c) {
            $s .= '<wp:comment>'
                . '<wp:comment_id>' . ($c['id'] ?? $n + 1) . '</wp:comment_id>'
                . '<wp:comment_author><![CDATA[' . ($c['author'] ?? 'Guest') . ']]></wp:comment_author>'
                . '<wp:comment_author_email><![CDATA[' . ($c['email'] ?? 'g@example.test') . ']]></wp:comment_author_email>'
                . '<wp:comment_date_gmt>' . ($c['date'] ?? '2024-02-01 00:00:00') . '</wp:comment_date_gmt>'
                . '<wp:comment_content><![CDATA[' . ($c['content'] ?? '<p>Nice work.</p>') . ']]></wp:comment_content>'
                . '<wp:comment_approved>' . ($c['approved'] ?? '1') . '</wp:comment_approved>'
                . '<wp:comment_type>' . ($c['type'] ?? '') . '</wp:comment_type>';
            foreach (($c['meta'] ?? []) as $k => $v) {
                $s .= '<wp:commentmeta><wp:meta_key>' . htmlspecialchars((string) $k, ENT_XML1) . '</wp:meta_key>'
                    . '<wp:meta_value>' . htmlspecialchars((string) $v, ENT_XML1) . '</wp:meta_value></wp:commentmeta>';
            }
            $s .= '</wp:comment>';
        }
        return $s . '</item>';
    }

    /** ACF-era (5.0-5.7) story: real values under the same keys plus junk
     *  `_fictioneer_*` reference rows and the `_word_count` cache. */
    public static function acfStory(int $id, array $over = []): string
    {
        return self::item(array_merge([
            'id' => $id, 'type' => 'fcn_story', 'slug' => 'acf-story-' . $id, 'title' => 'ACF Story ' . $id,
            'meta' => [
                'fictioneer_story_rating' => 'Teen',
                'fictioneer_story_status' => 'Ongoing',
                'fictioneer_story_chapters' => serialize([100 + $id]),
                'fictioneer_story_short_description' => 'Old-era blurb.',
                'fictioneer_story_co_authors' => serialize([4]),
                'fictioneer_seo_title' => 'Old SEO Title',
                'fictioneer_seo_description' => 'Old SEO description',
                '_fictioneer_story_short_description' => 'field_abc123',
                '_word_count' => '999',
                'fictioneer_story_data_collection' => serialize(['cache' => 'stale']),
            ],
        ], $over));
    }

    /** 5.35-era story: fcn_hidden status and the new SEO array. No prose
     *  of its own (so no one-shot chapter is synthesized for it). */
    public static function hiddenStory(int $id): string
    {
        return self::item([
            'id' => $id, 'type' => 'fcn_story', 'status' => 'fcn_hidden', 'slug' => 'hidden-story-' . $id,
            'title' => 'Hidden Story', 'login' => 'beta', 'content' => '',
            'meta' => [
                'fictioneer_story_rating' => 'Mature',
                'fictioneer_story_status' => 'Completed',
                'fictioneer_story_chapters' => serialize([]),
                'fictioneer_seo_fields' => serialize(['description' => 'New era SEO', 'title' => 'T']),
            ],
        ]);
    }

    public static function chapter(int $id, int $storyWpId, array $over = []): string
    {
        return self::item(array_merge([
            'id' => $id, 'type' => 'fcn_chapter', 'slug' => 'chapter-' . $id,
            'meta' => ['fictioneer_chapter_story' => (string) $storyWpId],
        ], $over));
    }
}
