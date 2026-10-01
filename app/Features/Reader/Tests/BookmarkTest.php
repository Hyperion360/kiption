<?php // app/Features/Reader/Tests/BookmarkTest.php
namespace App\Features\Reader\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// C5: chapter bookmarks, a member-only vertical slice. The two POST actions
// resolve (story, chapter) by slug + validated position in ONE query behind
// read()'s exact restricted gate, write inside one transaction, and redirect
// back to the chapter. The read/view envelopes fold the member's bookmarks
// for the story into the SAME single query (the budget law); guests get the
// cookieless shape with no bookmark markup and no other reader's notes.
final class BookmarkTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-bm-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // betafriend is the seeded NON-author member: the acting reader.
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-bm-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function rowCount(): int
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM bookmarks")['c'];
    }

    public function test_member_add_creates_row_and_redirects_to_chapter(): void
    {
        $res = $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'great twist']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('/story/read/the-rabbit-hole/2', $res->headers['Location']);
        $row = $this->db->one(
            'SELECT b.note, s.slug, c.position FROM bookmarks b
             JOIN stories s ON s.id = b.story_id JOIN chapters c ON c.id = b.chapter_id');
        $this->assertSame('great twist', $row['note']);
        $this->assertSame('the-rabbit-hole', $row['slug']);
        $this->assertSame(2, (int) $row['position']);
    }

    public function test_note_is_trimmed_and_capped_at_500(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1',
            ['note' => '  ' . str_repeat('a', 600) . '  ']);
        $note = (string) $this->db->one('SELECT note FROM bookmarks')['note'];
        $this->assertSame(500, strlen($note));
        $this->assertSame(str_repeat('a', 500), $note);
    }

    public function test_note_containing_script_renders_escaped_on_the_read_page(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2',
            ['note' => '<script>alert(1)</script>']);
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert', $body);
    }

    public function test_readding_the_same_chapter_updates_the_note(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'first']);
        $client->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'second']);
        $this->assertSame(1, $this->rowCount());
        $this->assertSame('second', (string) $this->db->one('SELECT note FROM bookmarks')['note']);
    }

    public function test_remove_deletes_the_row(): void
    {
        $client = $this->client($this->memberId);
        $client->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'x']);
        $res = $client->postWithToken('/reader/bookmarkremove/the-rabbit-hole/2');
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame('/story/read/the-rabbit-hole/2', $res->headers['Location']);
        $this->assertSame(0, $this->rowCount());
        $this->assertSame(302, $client->postWithToken('/reader/bookmarkremove/the-rabbit-hole/2')->status); // idempotent
    }

    public function test_guest_post_redirects_to_login(): void
    {
        // the kernel's AuthAttr gate: login before CSRF, so a guest learns
        // nothing about the route beyond the login redirect itself
        $res = $this->client()->post('/reader/bookmarkadd/the-rabbit-hole/2');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location']);
        $this->assertSame(0, $this->rowCount());
    }

    public function test_tokenless_member_post_is_403(): void
    {
        $res = $this->client($this->memberId)->post('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'x']);
        $this->assertSame(403, $res->status);
        $this->assertSame(0, $this->rowCount());
    }

    public function test_unknown_chapter_or_story_404s(): void
    {
        $client = $this->client($this->memberId);
        $this->assertSame(404, $client->postWithToken('/reader/bookmarkadd/the-rabbit-hole/99')->status);
        $this->assertSame(404, $client->postWithToken('/reader/bookmarkadd/nope/1')->status);
        $this->assertSame(404, $client->postWithToken('/reader/bookmarkremove/nope/1')->status);
    }

    /** read()'s restricted gate, copied verbatim: members bookmark what they
     *  may read, and the gate stays in SQL so the 404 shape is identical. */
    public function test_restricted_story_bookmarks_for_members_who_may_read(): void
    {
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $res = $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'r']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, $this->rowCount());
    }

    /** The read path folds the member's bookmarks into the SAME single query
     *  (the budget law); the guest render carries none of it, so no reader
     *  ever sees another's notes and cached guest bytes stay unchanged. */
    public function test_read_page_lists_member_bookmarks_guest_sees_none(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'stop here']);
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('stop here', $body);
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $body);
        $guestBody = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringNotContainsString('stop here', $guestBody);
        $this->assertStringNotContainsString('Bookmark this chapter', $guestBody);
        $this->assertStringNotContainsString('bookmarks', $guestBody);
    }
}
