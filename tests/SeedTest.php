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
        // the fresh path never deletes the demo user, so a pre-existing
        // demo@example.test row makes the users INSERT fail mid-seed;
        // the transaction must restore exactly the pre-seed state
        $db = new Database($this->dsn);
        $db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, \'\', 1)', ['Solo']);
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)',
            ['demo@example.test', 'x', 'Earlier User']);
        try {
            \App\Seeder::run($db);
            $this->fail('Seeder should have hit the UNIQUE user constraint');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM ratings')['c'], 'ratings rolled back');
        $this->assertSame('Solo', $db->one('SELECT label FROM ratings')['label']);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM stories')['c'], 'no stories leaked');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM users')['c'], 'the earlier user survived');
    }
}
