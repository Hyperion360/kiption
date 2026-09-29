<?php // app/Features/User/Tests/ProfileSlugTest.php
namespace App\Features\User\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class ProfileSlugTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pslug-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_seeder_grandfathered_penname_gets_transliterated_slug(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $row = $db->one("SELECT profile_slug FROM users WHERE penname = 'Demo Author'");
        $this->assertSame('demo-author', $row['profile_slug']);
        $beta = $db->one("SELECT profile_slug, is_beta FROM users WHERE penname = 'betafriend'");
        $this->assertSame('betafriend', $beta['profile_slug']);
        $this->assertSame(1, (int) $beta['is_beta']);
    }

    public function test_slug_collisions_suffix_deterministically(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query('UPDATE users SET profile_slug = ? WHERE penname = ?', ['taken-name', 'Demo Author']);
        // A second member whose penname maps to the same slug gets -2.
        $db->query("INSERT INTO users (email, password_hash, penname, profile_slug, email_verified_at, approved_at) VALUES ('c@e.test', ?, 'Taken Name', NULL, ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $id = (int) $db->lastInsertId();
        (new \App\Repositories\UserRepository($db))->backfillProfileSlug($id);
        $this->assertSame('taken-name-2', $db->one('SELECT profile_slug FROM users WHERE id = ?', [$id])['profile_slug']);
    }

    public function test_series_fixture_exists(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $s = $db->one("SELECT ser.slug, ser.membership, si.confirmed, st.slug story FROM series ser JOIN series_items si ON si.series_id = ser.id JOIN stories st ON st.id = si.story_id WHERE ser.slug = 'down-the-rabbit-hole'");
        $this->assertSame('open', $s['membership']);
        $this->assertSame(1, (int) $s['confirmed']);
        $this->assertSame('the-rabbit-hole', $s['story']);
    }
}
