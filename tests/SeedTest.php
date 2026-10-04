<?php // tests/SeedTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SeedTest extends TestCase
{
    private string $dsn = '';

    protected function setUp(): void
    {
        $this->dsn = 'sqlite:' . tempnam(sys_get_temp_dir(), 'kiption-seed-') . '.sqlite';
        (new Migrator(new Database($this->dsn), \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    public function test_seed_creates_demo_archive(): void
    {
        \App\Seeder::run(new Database($this->dsn));
        $db = new Database($this->dsn);
        $stories = $db->one('SELECT COUNT(*) c FROM stories')['c'];
        $this->assertSame(2, (int) $stories);
        $ratings = $db->one('SELECT COUNT(*) c FROM ratings')['c'];
        $this->assertSame(4, (int) $ratings);
        $adult = $db->one('SELECT COUNT(*) c FROM ratings WHERE is_adult = 1')['c'];
        $this->assertSame(2, (int) $adult);
        $chapters = $db->one('SELECT COUNT(*) c FROM chapters')['c'];
        $this->assertSame(4, (int) $chapters);
        $page = $db->one("SELECT title, body FROM pages WHERE slug = 'about'");
        $this->assertNotNull($page, 'the seeded about page exists');
        $this->assertSame('This archive is *new*.', $page['body'], 'markdown at rest, not pre-rendered');
    }

    public function test_seed_after_hours_chapter_row(): void
    {
        \App\Seeder::run(new Database($this->dsn));
        $row = (new Database($this->dsn))->one(
            "SELECT title, content, validated FROM chapters
             WHERE story_id = (SELECT id FROM stories WHERE slug = 'after-hours')");
        $this->assertNotNull($row);
        $this->assertSame('One', $row['title']);
        $this->assertSame('Body.', $row['content']);
        $this->assertSame(1, (int) $row['validated']);
    }

    public function test_seed_refuses_when_stories_exist(): void
    {
        $db = new Database($this->dsn);
        \App\Seeder::run($db);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already contains stories');
        \App\Seeder::run($db);
    }

    /** A stories-free database that already carries the fixture users (seed
     *  once, the stories deleted since, seed again without --force) re-seeds
     *  cleanly: our own fixture rows go with the taxonomy refresh, so the
     *  penname UNIQUE constraint never surfaces as a raw PDO fatal. */
    public function test_reseed_without_force_recycles_its_own_users(): void
    {
        $db = new Database($this->dsn);
        \App\Seeder::run($db);
        $db->query('DELETE FROM stories');
        \App\Seeder::run($db);
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM stories')['c']);
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM users WHERE penname = 'Demo Author'")['c']);
    }

    /** A squatted fixture email under a foreign penname is not ours to
     *  recycle: the seed fails loudly on the email UNIQUE constraint and
     *  rolls back (red-team: the unscoped recycle DELETE silently deleted
     *  such accounts instead). */
    public function test_squatted_fixture_email_fails_loudly_and_rolls_back(): void
    {
        $db = new Database($this->dsn);
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)',
            ['demo@example.test', 'x', 'Squatter']);
        try {
            \App\Seeder::run($db);
            $this->fail('Seeder should have hit the UNIQUE email constraint');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $this->assertSame('Squatter', $db->one("SELECT penname FROM users WHERE email = 'demo@example.test'")['penname']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM stories')['c'], 'no stories leaked');
    }

    public function test_seed_force_wipes_and_reseeds(): void
    {
        $db = new Database($this->dsn);
        \App\Seeder::run($db);
        \App\Seeder::run($db, force: true);
        $stories = $db->one('SELECT COUNT(*) c FROM stories')['c'];
        $this->assertSame(2, (int) $stories);
        // force mode never clears pages (finding 12: operator/rider pages
        // survive), so the about page seeds OR IGNORE and stays idempotent
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) c FROM pages WHERE slug = 'about'")['c']);
    }

    public function test_failed_seed_rolls_back_completely(): void
    {
        // a penname squatter with a foreign email is not one of the seeder's
        // own fixture rows (the fresh path recycles only demo/beta@example.test),
        // so the users INSERT fails mid-seed on the penname UNIQUE constraint;
        // the transaction must restore exactly the pre-seed state
        $db = new Database($this->dsn);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, \'\', 1)', ['Solo']);
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)',
            ['squat@example.test', 'x', 'Demo Author']);
        try {
            \App\Seeder::run($db);
            $this->fail('Seeder should have hit the UNIQUE penname constraint');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM ratings')['c'], 'ratings rolled back');
        $this->assertSame('Solo', $db->one('SELECT label FROM ratings')['label']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM stories')['c'], 'no stories leaked');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'the squatter survived');
    }
}
