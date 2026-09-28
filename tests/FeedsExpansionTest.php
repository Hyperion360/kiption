<?php // tests/FeedsExpansionTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class FeedsExpansionTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-feedsexp-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-feedsexp-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-feedsexp-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function client(?int $userId = null): TestClient
    {
        $client = new TestClient(new App($this->config()));
        return $userId === null ? $client : $client->actingAs($userId);
    }

    private function clientWithConfig(array $overrides): TestClient
    {
        return new TestClient(new App($this->config($overrides)));
    }

    public function test_author_and_category_feeds(): void
    {
        $a = $this->client()->get('/feed/author/demo-author');
        $this->assertSame(200, $a->status);
        $this->assertStringContainsString('application/atom+xml', $a->headers['Content-Type'] ?? '');
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $a->body);
        $this->assertStringContainsString('Demo Author', $a->body); // feed title carries the penname (finding 3: the seeder penname)
        $xml = simplexml_load_string($a->body);
        $this->assertNotFalse($xml, 'the author feed is well-formed XML');
        $this->assertSame('Demo Author', (string) $xml->title);
        $c = $this->client()->get('/feed/category/general');
        $this->assertSame(200, $c->status);
        $this->assertStringContainsString('the-rabbit-hole', $c->body);
        $this->assertSame(404, $this->client()->get('/feed/author/ghost')->status);
        $this->assertSame(404, $this->client()->get('/feed/category/ghost')->status);
    }

    public function test_empty_author_and_category_render_valid_empty_feeds(): void
    {
        // betafriend: unlocked with a penname, zero stories -> the NULL-story
        // anchor row renders a valid EMPTY feed, not a 404.
        $a = $this->client()->get('/feed/author/betafriend');
        $this->assertSame(200, $a->status);
        $xml = simplexml_load_string($a->body);
        $this->assertNotFalse($xml);
        $this->assertSame('betafriend', (string) $xml->title);
        $this->assertCount(0, $xml->entry);
        // same semantics for a category with no (gated) stories.
        $this->db()->query("INSERT INTO categories (name, slug, description) VALUES ('Void', 'void', 'Nothing yet.')");
        $c = $this->client()->get('/feed/category/void');
        $this->assertSame(200, $c->status);
        $xml = simplexml_load_string($c->body);
        $this->assertNotFalse($xml);
        $this->assertSame('Void', (string) $xml->title);
        $this->assertCount(0, $xml->entry);
    }

    public function test_full_text_mode_config_carries_content(): void
    {
        $this->db()->query("UPDATE stories SET crosspost_url = NULL, canonical_url = 'https://rr.example/x' WHERE slug = 'after-hours'");
        $res = $this->clientWithConfig(['feeds_full_text' => true])->get('/feed');
        $this->assertStringContainsString('<content', $res->body);
        $this->assertStringContainsString('<content type="html">', $res->body, 'finding 9: Atom content defaults to text');
        $this->assertStringContainsString('Falling', $res->body, 'chapter prose rides the entry');
        $this->assertNotFalse(simplexml_load_string($res->body), 'finding 9: the escaped rendered HTML round-trips as XML');
        $this->assertStringNotContainsString('after-hours', $res->body, 'external-canonical stories leave the feeds');
        // summary mode (default): no content element
        $this->assertStringNotContainsString('<content', $this->client()->get('/feed')->body);
    }

    public function test_full_text_mode_covers_the_author_and_category_feeds(): void
    {
        foreach (['/feed/author/demo-author', '/feed/category/general'] as $uri) {
            $res = $this->clientWithConfig(['feeds_full_text' => true])->get($uri);
            $this->assertSame(200, $res->status, $uri);
            $this->assertStringContainsString('<content type="html">', $res->body, $uri);
            $this->assertStringContainsString('Falling', $res->body, $uri);
        }
    }

    public function test_rss_shares_the_canonical_exclusion(): void
    {
        $this->db()->query("UPDATE stories SET canonical_url = 'https://rr.example/x' WHERE slug = 'after-hours'");
        $xml = simplexml_load_string($this->client()->get('/rss')->body);
        $this->assertNotFalse($xml);
        $this->assertCount(1, $xml->channel->item, 'finding 14: /rss rides the shared feed base');
    }

    public function test_profile_and_category_pages_carry_feed_autodiscovery(): void
    {
        $p = $this->client()->get('/user/view/demo-author');
        $this->assertSame(200, $p->status);
        $this->assertStringContainsString('rel="alternate" type="application/atom+xml"', $p->body);
        $this->assertStringContainsString('href="/feed/author/demo-author"', $p->body);
        $c = $this->client()->get('/browse/category/general');
        $this->assertSame(200, $c->status);
        $this->assertStringContainsString('rel="alternate" type="application/atom+xml"', $c->body);
        $this->assertStringContainsString('href="/feed/category/general"', $c->body);
    }
}
