<?php // app/Features/Reader/Tests/ReaderThemeMigrationTest.php
namespace App\Features\Reader\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

// Migration 026 rebuilds user_prefs (SQLite cannot ALTER a CHECK): the legacy
// theme CHECK (dark|light) becomes (paper|sepia|night) with legacy rows
// mapped once. The rebuild must carry EVERY live column (001 base + 010's
// digest_sent_at/notify_favorite_digest + 024's lang) or data is lost, so the
// round trip seeds a row with every column off its default and asserts each
// value survives both directions.
final class ReaderThemeMigrationTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rtm-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Everything except the Reader feature's own migrations, so 026 (once it
     *  exists) is the only file left and lands alone in its own batch. */
    private function baseDirs(): array
    {
        return array_values(array_filter(
            \App\Tests\Support\AppLayout::migrations(),
            static fn (string $d): bool => !str_contains($d, '/Features/Reader/migrations')));
    }

    /** The row under test: every column off its schema default. */
    private function seedMemberWithFullPrefs(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('rtm@e.test', ?, 'rtmmember')",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $uid = (int) $this->db->lastInsertId();
        $this->db->query(
            'INSERT INTO user_prefs (user_id, notify_review, notify_response, notify_favorites, default_sort, toc_first, theme, digest_sent_at, notify_favorite_digest, lang)
             VALUES (?, 0, 0, 0, \'alpha\', 1, \'dark\', \'2026-09-01T00:00:00Z\', 1, \'de\')', [$uid]);
    }

    /** @return array<string, mixed> the seeded row keyed by column */
    private function prefsRow(): array
    {
        return $this->db->one('SELECT * FROM user_prefs WHERE user_id = (SELECT id FROM users WHERE penname = \'rtmmember\')');
    }

    public function test_migration_rebuild_maps_theme_and_keeps_every_column_round_trip(): void
    {
        // Batch 1: everything through 025, then the seeded legacy row.
        (new Migrator($this->db, $this->baseDirs()))->migrate();
        $this->seedMemberWithFullPrefs();

        // Up: 026 runs alone (its own batch, the real Migrator mechanics).
        $ran = (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->assertContains('026_reader_theme_values', $ran, 'migration 026 must run');
        $up = $this->prefsRow();
        $this->assertNotNull($up, 'the row survived the rebuild');
        $this->assertSame('night', $up['theme'], 'legacy dark maps to night');
        $this->assertSame(0, (int) $up['notify_review']);
        $this->assertSame(0, (int) $up['notify_response']);
        $this->assertSame(0, (int) $up['notify_favorites']);
        $this->assertSame('alpha', $up['default_sort']);
        $this->assertSame(1, (int) $up['toc_first']);
        $this->assertSame('2026-09-01T00:00:00Z', $up['digest_sent_at'], 'the 010 column survives');
        $this->assertSame(1, (int) $up['notify_favorite_digest'], 'the 010 column survives');
        $this->assertSame('de', $up['lang'], 'the 024 column survives');
        // The new CHECK is live: legacy values refuse, the new set accepts.
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('rtm2@e.test', ?, 'rtmsecond')",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $this->db->query('INSERT INTO user_prefs (user_id) VALUES ((SELECT id FROM users WHERE penname = \'rtmsecond\'))');
        $this->assertSame('paper', $this->db->one("SELECT theme FROM user_prefs WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmsecond')")['theme'],
            'the schema default is now paper');
        try {
            $this->db->query('UPDATE user_prefs SET theme = \'dark\' WHERE user_id = (SELECT id FROM users WHERE penname = \'rtmmember\')');
            $this->fail('the rebuilt CHECK must reject the legacy dark value');
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('CHECK', $e->getMessage());
        }

        // Down: rollback reverses exactly the 026 batch (the whole batch is
        // atomic per the Migrator contract).
        $reversed = (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->rollback();
        $this->assertContains('026_reader_theme_values', $reversed);
        $down = $this->prefsRow();
        $this->assertNotNull($down, 'the row survived the down rebuild too');
        $this->assertSame('dark', $down['theme'], 'night maps back to dark');
        $this->assertSame(0, (int) $down['notify_review']);
        $this->assertSame(0, (int) $down['notify_response']);
        $this->assertSame(0, (int) $down['notify_favorites']);
        $this->assertSame('alpha', $down['default_sort']);
        $this->assertSame(1, (int) $down['toc_first']);
        $this->assertSame('2026-09-01T00:00:00Z', $down['digest_sent_at']);
        $this->assertSame(1, (int) $down['notify_favorite_digest']);
        $this->assertSame('de', $down['lang']);
    }
}
