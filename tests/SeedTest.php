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
        (new Migrator(new Database($this->dsn), dirname(__DIR__) . '/app/migrations'))->migrate();
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
    }

    public function test_seed_after_hours_chapter_row(): void
    {
        \App\Seeder::run(new Database($this->dsn));
        $row = (new Database($this->dsn))->one(
            "SELECT title, content, validated FROM chapters
             WHERE story_id = (SELECT id FROM stories WHERE slug = 'after-hours')");
        $this->assertNotNull($row);
        $this->assertSame('One', $row['title']);
        $this->assertSame('<p>Body.</p>', $row['content']);
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
    }
}
