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

    private string $readerTo028 = '';

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        if ($this->readerTo028 !== '') { array_map('unlink', glob($this->readerTo028 . '/*') ?: []); @rmdir($this->readerTo028); }
    }

    /** The Reader migrations as of 028 (026-028), without 029: the 026 round
     *  trip below pins 026's own mapping, which 029 deliberately resets. */
    private function dirsThrough028(): array
    {
        $this->readerTo028 = sys_get_temp_dir() . '/kiption-rtm-reader-' . bin2hex(random_bytes(4));
        mkdir($this->readerTo028);
        foreach (glob(dirname(__DIR__) . '/migrations/*.php') ?: [] as $f) {
            if ((int) basename($f) <= 28) copy($f, $this->readerTo028 . '/' . basename($f));
        }
        return array_merge($this->baseDirs(), [$this->readerTo028]);
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
        // Batch 1: everything through 025, then the seeded legacy rows.
        (new Migrator($this->db, $this->baseDirs()))->migrate();
        $this->seedMemberWithFullPrefs();
        // The light arm too (testing specialist: a typo in either CASE branch
        // would otherwise ship green; dark is the round-trip row above).
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('rtm-light@e.test', ?, 'rtmlight')",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $this->db->query('INSERT INTO user_prefs (user_id, theme) VALUES ((SELECT id FROM users WHERE penname = \'rtmlight\'), \'light\')');

        // Up: 026 runs alone (its own batch, the real Migrator mechanics).
        $ran = (new Migrator($this->db, $this->dirsThrough028()))->migrate();
        $this->assertContains('026_reader_theme_values', $ran, 'migration 026 must run');
        $up = $this->prefsRow();
        $this->assertNotNull($up, 'the row survived the rebuild');
        $this->assertSame('night', $up['theme'], 'legacy dark maps to night');
        $this->assertSame('paper', $this->db->one("SELECT theme FROM user_prefs WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmlight')")['theme'],
            'legacy light maps to paper');
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
        $reversed = (new Migrator($this->db, $this->dirsThrough028()))->rollback();
        $this->assertContains('026_reader_theme_values', $reversed);
        $down = $this->prefsRow();
        $this->assertNotNull($down, 'the row survived the down rebuild too');
        $this->assertSame('dark', $down['theme'], 'night maps back to dark');
        $this->assertSame('light', $this->db->one("SELECT theme FROM user_prefs WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmlight')")['theme'],
            'paper maps back to light');
        $this->assertSame(0, (int) $down['notify_review']);
        $this->assertSame(0, (int) $down['notify_response']);
        $this->assertSame(0, (int) $down['notify_favorites']);
        $this->assertSame('alpha', $down['default_sort']);
        $this->assertSame(1, (int) $down['toc_first']);
        $this->assertSame('2026-09-01T00:00:00Z', $down['digest_sent_at']);
        $this->assertSame(1, (int) $down['notify_favorite_digest']);
        $this->assertSame('de', $down['lang']);
    }

    /** 029: the theme column turns nullable (NULL is Auto) and every stored
     *  value resets, because 001's DEFAULT 'dark' (night after 026) cannot be
     *  told apart from a real choice; every other column survives; the new
     *  CHECK accepts NULL and the three themes and still refuses legacy
     *  values; rollback restores 026's NOT NULL shape with NULL as paper. */
    public function test_029_resets_inherited_themes_and_stores_auto_as_null(): void
    {
        (new Migrator($this->db, $this->baseDirs()))->migrate();
        $this->seedMemberWithFullPrefs();
        $ran = (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->assertContains('029_user_prefs_theme_nullable', $ran);
        $up = $this->prefsRow();
        $this->assertNull($up['theme'], 'the inherited dark/night resets to Auto');
        $this->assertSame('alpha', $up['default_sort']);
        $this->assertSame(1, (int) $up['toc_first']);
        $this->assertSame('2026-09-01T00:00:00Z', $up['digest_sent_at']);
        $this->assertSame(1, (int) $up['notify_favorite_digest']);
        $this->assertSame('de', $up['lang']);
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('rtm3@e.test', ?, 'rtmthird')",
            [password_hash('password123', PASSWORD_DEFAULT)]);
        $this->db->query("INSERT INTO user_prefs (user_id, lang) VALUES ((SELECT id FROM users WHERE penname = 'rtmthird'), 'fr')");
        $this->assertNull($this->db->one("SELECT theme FROM user_prefs WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmthird')")['theme'],
            'a row created for another reason no longer carries a theme');
        $this->db->query("UPDATE user_prefs SET theme = 'sepia' WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmthird')");
        try {
            $this->db->query("UPDATE user_prefs SET theme = 'dark' WHERE user_id = (SELECT id FROM users WHERE penname = 'rtmthird')");
            $this->fail('the CHECK still refuses legacy values');
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase('CHECK', $e->getMessage());
        }
        // rollback of the whole batch (026-029) ends on the pre-026 shape; the
        // NULL row passes 029's down as paper and 026's down as light
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->rollback();
        $this->assertSame('light', $this->prefsRow()['theme']);
    }
}
