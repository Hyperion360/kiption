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

    /** Review CRITICAL (data-migration + red team): a chapter hard-delete with
     *  no bookmarks cleanup strands member rows whose fold LEFT JOIN then
     *  yields a null position, and bookmarkRemove 404s because the chapter
     *  can no longer be resolved. The delete transactions now cascade. */
    public function test_chapter_hard_delete_cascades_member_bookmarks(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'doomed']);
        $this->assertSame(1, $this->rowCount());
        $authorId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        (new \App\Repositories\AuthoringRepository($this->db))->deleteChapter('the-rabbit-hole', 2, $authorId);
        $this->assertSame(0, $this->rowCount(),
            'deleting the chapter takes its bookmarks with it; no orphaned, un-removable rows');
        $res = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringNotContainsString('doomed', $res->body);
    }

    /** The defensive render path for a bookmark whose chapters row is gone
     *  (only reachable by manual corruption: 030's foreign keys cascade):
     *  the note survives, the page stays 200, and no chapter link is emitted. */
    public function test_bookmark_whose_chapter_row_is_gone_renders_note_without_link(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'orphan note']);
        // since 030 the foreign key forbids this state, so the corruption is
        // simulated with enforcement off for the one write
        $this->db->query('PRAGMA foreign_keys = OFF');
        $this->db->query('UPDATE bookmarks SET chapter_id = chapter_id + 1000000');
        $this->db->query('PRAGMA foreign_keys = ON');
        $res = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/1');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('orphan note', $res->body, 'the note survives its chapter row');
    }

    /** Invalid UTF-8 in the note: SQLite's json_object would return NULL and
     *  blank the member's whole bookmark list; the write scrubs instead. */
    public function test_invalid_utf8_note_does_not_blank_the_bookmarks_blob(): void
    {
        // "\xC3(" is an invalid sequence; the rest is valid UTF-8.
        $res = $this->client($this->memberId)->postWithToken(
            '/reader/bookmarkadd/the-rabbit-hole/1', ['note' => "\xC3(valid tail)"]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(1, $this->rowCount());
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('(valid tail)', $body,
            'the readable part survives, scrubbed to U+FFFD where the garbage was');
        $this->assertStringNotContainsString('none yet', $body);
    }

    /** The cap is 500 characters, not bytes (docs corrected alongside); a
     *  multibyte note stores up to ~2000 bytes legally. */
    public function test_multibyte_note_honors_the_500_character_cap(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1',
            ['note' => str_repeat("\u{00E9}", 600)]);
        $note = (string) $this->db->one('SELECT note FROM bookmarks')['note'];
        $this->assertSame(500, mb_strlen($note), '600 multibyte chars truncate to exactly 500 chars');
        $this->assertGreaterThan(500, strlen($note), 'the stored bytes exceed 500: the cap is characters');
    }

    /** Adversarial F6: bookmarkTarget enforces the same adult gate read()
     *  does; members bookmark what they may read. */
    public function test_adult_story_refuses_bookmarks_until_age_acked(): void
    {
        $this->db->query("UPDATE stories SET rating_id = (SELECT id FROM ratings WHERE label = 'Explicit') WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'x'])->status,
            'no age_ok cookie: the bookmark is refused');
        $this->assertSame(0, $this->rowCount());
        $acked = $this->client($this->memberId);
        $acked->cookie('age_ok', '1');
        $this->assertSame(302, $acked->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'x'])->status,
            'the acknowledged member bookmarks normally');
        $this->assertSame(1, $this->rowCount());
    }

    /** The remove action shares the add's gate (bookmarkTarget): without the
     *  age acknowledgement an adult story's bookmark is neither confirmed nor
     *  removed (testing specialist). */
    public function test_adult_story_refuses_bookmark_removal_until_age_acked(): void
    {
        $acked = $this->client($this->memberId);
        $acked->cookie('age_ok', '1');
        $acked->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'x']);
        $this->db->query("UPDATE stories SET rating_id = (SELECT id FROM ratings WHERE label = 'Explicit') WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/reader/bookmarkremove/the-rabbit-hole/1')->status);
        $this->assertSame(1, $this->rowCount(), 'refused: the row stays');
        $this->assertSame(302, $acked->postWithToken('/reader/bookmarkremove/the-rabbit-hole/1')->status);
        $this->assertSame(0, $this->rowCount());
    }

    /** Cross-member isolation (testing specialist): the fold and the actions
     *  key on the acting member; a bind-order regression must never leak or
     *  mutate another member's rows. */
    public function test_members_see_and_mutate_only_their_own_bookmarks(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, is_beta, email_verified_at, approved_at, profile_slug)
            VALUES ('gamma@example.test', 'x', 'gammafriend', 1, '2026-01-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00', 'gammafriend')");
        $gamma = (int) $this->db->one("SELECT id FROM users WHERE penname = 'gammafriend'")['id'];
        $a = $this->client($this->memberId);
        $b = $this->client($gamma);
        $a->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'alpha note']);
        $b->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'beta note']);
        $this->assertSame(2, $this->rowCount());
        $aBody = $a->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('alpha note', $aBody);
        $this->assertStringNotContainsString('beta note', $aBody);
        $bBody = $b->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('beta note', $bBody);
        $this->assertStringNotContainsString('alpha note', $bBody);
        $a->postWithToken('/reader/bookmarkremove/the-rabbit-hole/1');
        $this->assertSame(1, $this->rowCount(), "A's remove never touches B's row");
        $this->assertStringContainsString('beta note', $b->get('/story/read/the-rabbit-hole/2')->body);
    }

    /** Bookmarks follow their owner, story and chapter (migration 030):
     *  deleting any of them removes the rows, so notes never outlive an
     *  account and an id can never inherit someone else's bookmarks. */
    public function test_bookmarks_cascade_with_their_user_story_and_chapter(): void
    {
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'mine']);
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', []);
        $this->assertSame(2, $this->rowCount());
        $this->db->query("DELETE FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole') AND position = 2");
        $this->assertSame(1, $this->rowCount(), 'the chapter row took its bookmark with it');
        $this->db->query('DELETE FROM users WHERE id = ?', [$this->memberId]);
        $this->assertSame(0, $this->rowCount(), 'the account took its bookmarks with it');
        $fk = $this->db->all('PRAGMA foreign_key_list(bookmarks)');
        $this->assertEqualsCanonicalizing(['users', 'stories', 'chapters'], array_column($fk, 'table'));
    }

    /** The ribbon button posts no note field. A double click, a resubmit or a
     *  stale tab re-bookmarking a chapter must keep its saved note; only the
     *  note form (which always sends the field) may change or clear it. */
    public function test_rebookmark_without_note_field_keeps_the_note(): void
    {
        $c = $this->client($this->memberId);
        $c->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => 'keep me']);
        $c->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', []);
        $this->assertSame('keep me', (string) $this->db->one('SELECT note FROM bookmarks')['note']);
        $c->postWithToken('/reader/bookmarkadd/the-rabbit-hole/1', ['note' => '']);
        $this->assertSame('', (string) $this->db->one('SELECT note FROM bookmarks')['note'], 'an explicit empty note clears it');
        $this->assertSame(1, $this->rowCount());
    }
}
