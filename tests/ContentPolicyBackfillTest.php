<?php // tests/ContentPolicyBackfillTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** Migration 036 backfills the content-policy page onto installs that predate
 *  the Seeder::bootstrap addition. Three contracts: a missing page is inserted
 *  with the launch clause; an operator-authored page with the same slug is
 *  never touched; down() lifts only a row the migration itself would have
 *  inserted (the clause fingerprint), never an operator's text. */
final class ContentPolicyBackfillTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-cpb-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function page(): ?array
    {
        $row = $this->db->one("SELECT title, body FROM pages WHERE slug = 'content-policy'");
        return $row === null ? null : $row;
    }

    public function test_missing_page_is_backfilled_with_the_clause(): void
    {
        $row = $this->page();
        $this->assertNotNull($row, 'the backfill inserted the policy page');
        $this->assertSame('Content policy', $row['title']);
        $this->assertStringContainsString('material written to arouse is out of scope', $row['body']);
    }

    public function test_an_operator_authored_page_survives_the_backfill(): void
    {
        // Simulate a pre-036 install whose operator already wrote the page:
        // drop the backfilled row, insert theirs, re-run the migration.
        $this->db->query("DELETE FROM pages WHERE slug = 'content-policy'");
        $this->db->query("INSERT INTO pages (slug, title, body) VALUES ('content-policy', 'House rules', 'Be kind.')");
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $row = $this->page();
        $this->assertSame('House rules', $row['title'], 'OR IGNORE never overwrites an operator page');
        $this->assertSame('Be kind.', $row['body']);
    }

    public function test_down_lifts_only_the_backfilled_row(): void
    {
        $migration = require dirname(__DIR__) . '/app/migrations/036_content_policy_page.php';
        $migration->down($this->db);
        $this->assertNull($this->page(), 'the backfilled row is lifted with the migration');
    }
}
