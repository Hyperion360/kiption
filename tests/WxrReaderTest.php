<?php // tests/WxrReaderTest.php
use App\Import\WxrReader;
use PHPUnit\Framework\TestCase;

final class WxrReaderTest extends TestCase
{
    private function write(string $xml): string
    {
        $p = tempnam(sys_get_temp_dir(), 'wxr-') . '.xml';
        file_put_contents($p, $xml);
        return $p;
    }

    private function channel(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"
            xmlns:content="http://purl.org/rss/1.0/modules/content/"
            xmlns:dc="http://purl.org/dc/elements/1.1/"
            xmlns:wp="http://wordpress.org/export/1.2/"><channel>' . $items . '</channel></rss>';
    }

    private function item(array $over = []): string
    {
        $id = $over['id'] ?? 1;
        return '<item><title>T' . $id . '</title>'
            . '<dc:creator><![CDATA[' . ($over['login'] ?? 'anna') . ']]></dc:creator>'
            . '<content:encoded><![CDATA[<p>' . ($over['content'] ?? 'Body') . '</p>]]></content:encoded>'
            . '<wp:post_id>' . $id . '</wp:post_id>'
            . '<wp:post_date_gmt>2024-01-01 00:00:00</wp:post_date_gmt>'
            . '<wp:post_modified_gmt>2024-01-02 00:00:00</wp:post_modified_gmt>'
            . '<wp:status>' . ($over['status'] ?? 'publish') . '</wp:status>'
            . '<wp:post_name>slug-' . $id . '</wp:post_name>'
            . '<wp:post_type>' . ($over['type'] ?? 'fcn_chapter') . '</wp:post_type>'
            . '<wp:post_password>' . ($over['password'] ?? '') . '</wp:post_password>'
            . '<wp:postmeta><wp:meta_key>fictioneer_chapter_story</wp:meta_key><wp:meta_value>9</wp:meta_value></wp:postmeta>'
            . '<category domain="fcn_genre" nicename="fantasy"><![CDATA[Fantasy]]></category>'
            . '</item>';
    }

    public function test_reads_items_with_meta_terms_and_comments(): void
    {
        $p = $this->write($this->channel($this->item(['id' => 5])
            . '<item><wp:post_id>6</wp:post_id><wp:post_type>fcn_story</wp:post_type><wp:status>publish</wp:status>'
            . '<wp:comment><wp:comment_id>1</wp:comment_id><wp:comment_author><![CDATA[Go]]></wp:comment_author>'
            . '<wp:comment_content><![CDATA[<p>Hi</p>]]></wp:comment_content><wp:comment_approved>1</wp:comment_approved>'
            . '<wp:comment_type></wp:comment_type><wp:commentmeta><wp:meta_key>x</wp:meta_key><wp:meta_value>y</wp:meta_value></wp:commentmeta></wp:comment></item>'));
        $r = new WxrReader($p);
        $items = iterator_to_array($r->items(), false);
        $this->assertCount(2, $items);
        $this->assertSame('fcn_chapter', $items[0]['type']);
        $this->assertSame(5, $items[0]['id']);
        $this->assertSame('anna', $items[0]['login']);
        $this->assertSame(['fictioneer_chapter_story' => '9'], $items[0]['meta']);
        $this->assertSame('fcn_genre', $items[0]['terms'][0]['domain']);
        $this->assertSame('Fantasy', $items[0]['terms'][0]['name']);
        $this->assertSame('Go', $items[1]['comments'][0]['author']);
        $this->assertSame(['x' => 'y'], $items[1]['comments'][0]['meta']);
        $this->assertSame(['fcn_chapter' => 1, 'fcn_story' => 1], $r->typeCounts);
    }

    public function test_refuses_doctype(): void
    {
        $p = $this->write('<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "b">]><rss version="2.0"><channel></channel></rss>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refuses DOCTYPE');
        iterator_to_array((new WxrReader($p))->items());
    }

    public function test_refuses_non_wxr_root(): void
    {
        $p = $this->write('<?xml version="1.0"?><foo/>');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a WXR file');
        iterator_to_array((new WxrReader($p))->items());
    }

    public function test_refuses_truncated_file(): void
    {
        $p = $this->write('<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><item><wp:post_id>1</wp:post_id>');
        $this->expectException(RuntimeException::class);
        iterator_to_array((new WxrReader($p))->items());
    }

    public function test_refuses_channel_tail_truncation(): void
    {
        // EOF inside the channel tail (after wp:base_site_url, before any item):
        // the fall-through after the channel walk must not pass as an empty export
        $p = $this->write('<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
            . '<channel><wp:base_site_url>https://x.example/');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable WXR');
        iterator_to_array((new WxrReader($p))->items());
    }

    public function test_a_failed_reader_does_not_poison_the_next(): void
    {
        $bad = $this->write('<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
            . '<channel><wp:base_site_url>https://x.example/');
        try {
            iterator_to_array((new WxrReader($bad))->items());
            $this->fail('the truncated file must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unreadable WXR', $e->getMessage());
        }
        // a NEW reader over a well-formed file, same process, after the poisoned buffer
        $good = $this->write($this->channel($this->item(['id' => 8])));
        $items = iterator_to_array((new WxrReader($good))->items(), false);
        $this->assertCount(1, $items);
        $this->assertSame(8, $items[0]['id']);
    }

    public function test_gz_files_read_through_zlib(): void
    {
        $xml = $this->channel($this->item(['id' => 7]));
        $p = tempnam(sys_get_temp_dir(), 'wxr-') . '.xml.gz';
        file_put_contents($p, gzencode($xml));
        $items = iterator_to_array((new WxrReader($p))->items(), false);
        $this->assertCount(1, $items);
        $this->assertSame(7, $items[0]['id']);
    }

    public function test_item_and_total_caps_are_enforced(): void
    {
        // total cap: one item over WxrReader::MAX_ITEM_BYTES fails clean
        $p = $this->write($this->channel($this->item(['id' => 1, 'content' => str_repeat('x', WxrReader::MAX_ITEM_BYTES + 1)])));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('over the item cap');
        iterator_to_array((new WxrReader($p))->items());
    }

    public function test_channel_authors_and_site_host_are_read(): void
    {
        $p = $this->write('<?xml version="1.0"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
            . '<wp:base_site_url>https://Old.Example.org/</wp:base_site_url>'
            . '<wp:author><wp:author_id>3</wp:author_id><wp:author_login><![CDATA[anna]]></wp:author_login>'
            . '<wp:author_email><![CDATA[anna@example.org]]></wp:author_email><wp:author_display_name><![CDATA[Anna S.]]></wp:author_display_name></wp:author>'
            . $this->item(['id' => 1]) . '</channel></rss>');
        $r = new WxrReader($p);
        $this->assertSame('old.example.org', $r->siteHost());
        $this->assertSame(['login' => 'anna', 'email' => 'anna@example.org', 'display_name' => 'Anna S.'], $r->authors()[3]);
        $this->assertCount(1, iterator_to_array($r->items(), false), 'items still stream after the channel scan');
    }

    public function test_items_stream_once_per_call(): void
    {
        $p = $this->write($this->channel($this->item(['id' => 1]) . $this->item(['id' => 2])));
        $r = new WxrReader($p);
        $this->assertCount(2, iterator_to_array($r->items(), false));
        $this->assertCount(2, iterator_to_array($r->items(), false), 'the orchestrator streams once per pass: an XMLReader cannot rewind, so items() re-opens');
        $this->assertSame(['fcn_chapter' => 2], $r->typeCounts, 'per-pass counts, never accumulated');
    }
}
