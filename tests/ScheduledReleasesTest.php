<?php // tests/ScheduledReleasesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ScheduledReleasesTest extends TestCase
{
    private string $path = '';
    private string $cacheDir = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rel-') . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/kiption-rel-cache-' . uniqid('', true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-rel-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rel-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            // purge writes land in the temp root, never the repo's public/cache
            'static_cache' => ['dir' => $this->cacheDir],
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    /** A follower in email mode: the fan-out's recipient, so the notification
     *  and mail pins are meaningful. */
    private function plantFollower(): int
    {
        $this->db->query(
            "INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('relfan@e.test', ?, 'relfan', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $fan = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, ?, 'email')", [$fan, $this->authorId()]);
        return $fan;
    }

    /** @return array{0: int, 1: string} exit code, stdout+stderr (the CliTest
     *  idiom). Finding 6: KIP_STATIC_CACHE_DIR + --mail-log point the arm's
     *  purge and mailer at temps, never the repo's public/cache or app/mail.log. */
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($this->cacheDir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_schedule_storage_and_invisibility(): void
    {
        $fan = $this->plantFollower();
        $me = $this->client($this->authorId());
        $res = $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Later', 'content' => 'Scheduled words for tomorrow.', 'publish_at' => '2099-01-01T00:00:00Z']);
        $this->assertSame(302, $res->status, $res->body);
        $db = $this->db();
        $row = $db->one("SELECT validated, publish_at FROM chapters WHERE title = 'Later'");
        $this->assertSame(0, (int) $row['validated']);
        $this->assertSame('2099-01-01T00:00:00Z', $row['publish_at']);
        // The Demo Author is a validated_author, so autoValidates() is true:
        // scheduling is explicit and STILL stores validated = 0, and the create
        // fired no fan-out (finding 5: the follower learned nothing).
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM notifications WHERE user_id = ?', [$fan])['c'],
            'a scheduled create never notifies');
        // invisible to readers: not in the TOC blob
        $this->assertStringNotContainsString('Later', $this->client()->get('/story/view/the-rabbit-hole')->body);
        // the bare datetime-local shape (no zone, no seconds) stores canonical UTC Z-form
        $this->assertSame(302, $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Bare', 'content' => 'Words.', 'publish_at' => '2099-01-01T00:00'])->status);
        $this->assertSame('2099-01-01T00:00:00Z', $db->one("SELECT publish_at FROM chapters WHERE title = 'Bare'")['publish_at']);
        // an offset value normalizes to the same UTC instant (finding 1: raw
        // offsets compare wrong lexicographically, so storage canonicalizes)
        $this->assertSame(302, $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Offset', 'content' => 'Words.', 'publish_at' => '2099-01-01T05:30+02:00'])->status);
        $this->assertSame('2099-01-01T03:30:00Z', $db->one("SELECT publish_at FROM chapters WHERE title = 'Offset'")['publish_at']);
        // empty stays the immediate path (autoValidates still flips it live)
        $this->assertSame(302, $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'Now', 'content' => 'Words.'])->status);
        $now = $db->one("SELECT validated, publish_at FROM chapters WHERE title = 'Now'");
        $this->assertSame(1, (int) $now['validated']);
        $this->assertNull($now['publish_at']);
        // junk datetime rejected
        $this->assertSame(422, $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'X', 'content' => 'Words.', 'publish_at' => 'not a date'])->status);
        // a regex-shaped but unreal instant rejected too
        $this->assertSame(422, $me->postWithToken('/chapter/create/the-rabbit-hole',
            ['title' => 'X', 'content' => 'Words.', 'publish_at' => '2099-13-45T99:99'])->status);
        // a NEGATIVE offset normalizes (the east-of-UTC pin's mirror), and a
        // zone-less value reads UTC whatever the host default timezone is (the
        // normalizer pins UTC on every createFromFormat call)
        $hostTz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $this->assertSame(302, $me->postWithToken('/chapter/create/the-rabbit-hole',
                ['title' => 'Neg', 'content' => 'Words.', 'publish_at' => '2099-01-01T05:30-05:00'])->status);
            $this->assertSame(302, $me->postWithToken('/chapter/create/the-rabbit-hole',
                ['title' => 'Zoneless', 'content' => 'Words.', 'publish_at' => '2099-01-01T00:00'])->status);
        } finally { date_default_timezone_set($hostTz); }
        $this->assertSame('2099-01-01T10:30:00Z', $db->one("SELECT publish_at FROM chapters WHERE title = 'Neg'")['publish_at']);
        $this->assertSame('2099-01-01T00:00:00Z', $db->one("SELECT publish_at FROM chapters WHERE title = 'Zoneless'")['publish_at'],
            'a zone-less input never shifts with the host timezone');
    }

    public function test_schedule_set_and_clear_on_update(): void
    {
        $me = $this->client($this->authorId());
        $db = $this->db();
        // Chapter 1 of the seed, live; schedule it through the edit form
        $this->assertSame(302, $me->postWithToken('/chapter/update/the-rabbit-hole/1',
            ['title' => 'Down', 'content' => 'Falling words.', 'publish_at' => '2099-06-01T12:30'])->status);
        $row = $db->one("SELECT validated, publish_at FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole') AND position = 1");
        $this->assertSame('2099-06-01T12:30:00Z', $row['publish_at'], 'update can set a schedule (canonicalized)');
        $this->assertSame(1, (int) $row['validated'], 'an edited live chapter keeps its validated state; releaseDue ignores its stray publish_at');
        // the edit form repopulates the field from the stored value
        $form = $me->get('/chapter/edit/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('value="2099-06-01T12:30"', $form);
        // an emptied field clears it
        $this->assertSame(302, $me->postWithToken('/chapter/update/the-rabbit-hole/1',
            ['title' => 'Down', 'content' => 'Falling words.', 'publish_at' => ''])->status);
        $this->assertNull($db->one("SELECT publish_at FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole') AND position = 1")['publish_at'],
            'update can clear a schedule');
        // junk on update 422s as well
        $this->assertSame(422, $me->postWithToken('/chapter/update/the-rabbit-hole/1',
            ['title' => 'Down', 'content' => 'Falling words.', 'publish_at' => 'soonish'])->status);
    }

    public function test_release_due_flips_and_fans_out(): void
    {
        $fan = $this->plantFollower();
        $db = $this->db();
        // a stale static page for the story: the arm's purge must unlink it
        $page = $this->cacheDir . '/story/view/the-rabbit-hole/index.html';
        @mkdir(dirname($page), 0775, true);
        file_put_contents($page, 'stale');
        // plant a due chapter, run the real arm
        $db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count, publish_at) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 9, 'Due', 'Words.', 0, 1, '2000-01-01T00:00:00Z')");
        $mailLog = tempnam(sys_get_temp_dir(), 'kiption-rel-arm-') . '.log';
        [$code, $out] = $this->kip('release:due --mail-log=' . escapeshellarg($mailLog));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Released 1 chapter(s).', $out);
        $this->assertSame(1, (int) $db->one("SELECT validated FROM chapters WHERE title = 'Due'")['validated']);
        $this->assertNull($db->one("SELECT publish_at FROM chapters WHERE title = 'Due'")['publish_at'], 'cleared after release');
        // fan-out: the follower got the update notification and the immediate email
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND kind = 'update'", [$fan])['c']);
        $this->assertFileExists($mailLog);
        $this->assertStringContainsString('The Rabbit Hole', (string) file_get_contents($mailLog));
        // purge: the stale static page is gone, and readers now see the chapter
        $this->assertFileDoesNotExist($page);
        $this->assertStringContainsString('Due', $this->client()->get('/story/view/the-rabbit-hole')->body);
        // idempotent: a second run releases nothing
        [, $out2] = $this->kip('release:due --mail-log=' . escapeshellarg($mailLog));
        $this->assertStringContainsString('Released 0 chapter(s).', $out2);
        @unlink($mailLog);
    }

    public function test_fanout_mail_failure_still_lands_the_notification(): void
    {
        $fan = $this->plantFollower();
        $broken = new \App\PublishFanout($this->db,
            new \Kip\Mailer(['transport' => 'log', 'log_path' => '/nonexistent-dir/kip-qa-rel/nope.log', 'from' => 'noreply@localhost']),
            'https://archive.example');
        // @ on the call: the log transport warns before it throws; the catch
        // under test is PublishFanout's, not the transport diagnostic.
        @$broken->publish('the-rabbit-hole');
        $this->assertSame(1, (int) $this->db->one(
            "SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND kind = 'update'", [$fan])['c'],
            'a failing mail transport never blocks the notification rows');
    }
}
