<?php // app/Features/Feed/Tests/FeedTest.php
namespace App\Features\Feed\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class FeedTest extends TestCase
{
    private string $path = '';
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-feed-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $this->app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption',
            'base_url' => 'https://archive.example',
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_atom_feed_shape(): void
    {
        $res = $this->app->handle(new Request('GET', '/feed', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertSame('application/atom+xml; charset=utf-8', $res->headers['Content-Type']);
        $xml = simplexml_load_string($res->body);
        $this->assertNotFalse($xml);
        $this->assertSame('feed', $xml->getName());
        $this->assertSame('Kiption', (string) $xml->title);
        $entries = $xml->entry;
        $this->assertCount(2, $entries);
        $this->assertStringContainsString('after-hours', (string) $entries[0]->link['href']); // newest updated_at first per the seeder
    }

    public function test_rss_alias_shape(): void
    {
        $res = $this->app->handle(new Request('GET', '/rss', [], [], []));
        $this->assertSame('application/rss+xml; charset=utf-8', $res->headers['Content-Type']);
        $xml = simplexml_load_string($res->body);
        $this->assertSame('rss', $xml->getName());
        $this->assertCount(2, $xml->channel->item);
    }

    public function test_feed_escapes_markup(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query('UPDATE stories SET summary = ? WHERE slug = ?', ['<b>bold & "quoted"</b>', 'the-rabbit-hole']);
        $res = $this->app->handle(new Request('GET', '/feed', [], [], []));
        $this->assertNotFalse(simplexml_load_string($res->body), 'must remain well-formed XML');
    }

    public function test_feed_strips_xml_invalid_control_characters(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query('UPDATE stories SET title = ?, summary = ? WHERE slug = ?', ["Be\x0Btween", "Sum\x0Cma\x01ry", 'after-hours']);
        foreach (['/feed', '/rss'] as $uri) {
            $res = $this->app->handle(new Request('GET', $uri, [], [], []));
            $xml = simplexml_load_string($res->body);
            $this->assertNotFalse($xml, "{$uri} must stay well-formed XML under hostile text");
            $this->assertStringNotContainsString("\x0B", $res->body);
            $this->assertStringNotContainsString("\x0C", $res->body);
            $this->assertStringNotContainsString("\x01", $res->body);
        }
    }
}
