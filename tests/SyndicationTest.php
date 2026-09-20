<?php // tests/SyndicationTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class SyndicationTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-syn-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
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
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-syn-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-syn-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function client(?int $userId = null): TestClient
    {
        $client = new TestClient(new App($this->config()));
        return $userId === null ? $client : $client->actingAs($userId);
    }

    /** The seeded Demo Author, owner of the-rabbit-hole. */
    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    public function test_head_canonical_modes(): void
    {
        $h = \App\Seo\Head::make(siteName: 'Kiption', baseUrl: 'https://archive.example')->withCanonical('/story/view/x');
        $this->assertSame('https://archive.example/story/view/x', $h->canonical());
        // absolute external: used verbatim, never baseUrl-prefixed
        $this->assertSame('https://rr.example/works/1', $h->withCanonicalUrl('https://rr.example/works/1')->canonical());
        // suppressed: the LINK disappears but canonical() (og:url's source) is UNCHANGED (finding 5)
        $this->assertFalse($h->withCanonicalSuppressed()->rendersCanonicalLink());
        $this->assertSame('https://archive.example/story/view/x', $h->withCanonicalSuppressed()->canonical(), 'og:url keeps the self URL');
    }

    public function test_crosspost_state_renders_note_and_no_canonical(): void
    {
        $this->db()->query("UPDATE stories SET crosspost_url = 'https://rr.example/works/1' WHERE slug = 'the-rabbit-hole'");
        $res = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Cross-posted from', $res->body);
        $this->assertStringContainsString('href="https://rr.example/works/1"', $res->body);
        $this->assertStringContainsString('rel="nofollow"', $res->body);
        $this->assertStringNotContainsString('rel="canonical"', $res->body, 'cross-post state suppresses the canonical');
        // the SAME Head branch covers chapter reads (finding 10)
        $read = $this->client()->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $read->status);
        $this->assertStringNotContainsString('rel="canonical"', $read->body, 'chapter reads suppress the canonical too');
    }

    public function test_external_canonical_deindexes_locally(): void
    {
        $this->db()->query("UPDATE stories SET canonical_url = 'https://rr.example/works/2' WHERE slug = 'the-rabbit-hole'");
        $res = $this->client()->get('/story/view/the-rabbit-hole');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('rel="canonical" href="https://rr.example/works/2"', $res->body);
        $this->assertStringContainsString('noindex', $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '');
    }

    public function test_external_canonical_deindexes_chapter_reads_too(): void
    {
        $this->db()->query("UPDATE stories SET canonical_url = 'https://rr.example/works/2' WHERE slug = 'the-rabbit-hole'");
        $res = $this->client()->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('rel="canonical" href="https://rr.example/works/2"', $res->body);
        $this->assertStringContainsString('noindex', $res->body);
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '');
    }

    public function test_author_sets_and_clears_states_through_the_form(): void
    {
        $me = $this->client($this->authorId());
        // Finding 8's EXACT POST set: the seeded title verbatim (a changed title
        // regenerates the slug and 404s the follow-up POSTs), rating 2 = Teen,
        // categories [1] = general, deterministic on the fresh seed.
        $base = ['title' => 'The Rabbit Hole', 'rating_id' => 2, 'categories' => [1]];
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            $base + ['canonical_url' => 'https://rr.example/works/3', 'crosspost_url' => ''])->status);
        $row = $this->db()->one("SELECT canonical_url, crosspost_url FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertSame('https://rr.example/works/3', (string) $row['canonical_url']);
        $this->assertNull($row['crosspost_url']);
        $this->assertSame(422, $me->postWithToken('/story/update/the-rabbit-hole',
            $base + ['canonical_url' => 'javascript:alert(1)', 'crosspost_url' => ''])->status, 'scheme validation');
        $this->assertSame('https://rr.example/works/3', (string) $this->db()
            ->one("SELECT canonical_url FROM stories WHERE slug = 'the-rabbit-hole'")['canonical_url'], 'a 422 never writes');
        // both set at once is rejected (one state at a time)
        $this->assertSame(422, $me->postWithToken('/story/update/the-rabbit-hole',
            $base + ['canonical_url' => 'https://a.example/1', 'crosspost_url' => 'https://b.example/2'])->status);
        // clearing both returns the story to the self-canonical state
        $this->assertSame(302, $me->postWithToken('/story/update/the-rabbit-hole',
            $base + ['canonical_url' => '', 'crosspost_url' => ''])->status);
        $row = $this->db()->one("SELECT canonical_url, crosspost_url FROM stories WHERE slug = 'the-rabbit-hole'");
        $this->assertNull($row['canonical_url']);
        $this->assertNull($row['crosspost_url']);
    }
}
