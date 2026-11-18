<?php // app/Features/Authorimages/Tests/AuthorimagesTest.php
namespace App\Features\Authorimages\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The chapter editor's image upload: per-author library, administrator
 *  switch, magic bytes, retry by upload_key, storage refusal. */
final class AuthorimagesTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const KEY_A = '11111111-1111-4111-8111-111111111111';
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $alice = 0;
    private int $bob = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-aimg-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-aimg-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->alice = $this->user('alice');
        $this->bob = $this->user('bob');
        \App\Features::init($this->db, []);
        \App\Settings::put('chapter_images_on', '1');
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function user(string $name, array $over = []): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug, is_locked) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$name . '@e.test', password_hash('password123', PASSWORD_DEFAULT), $name,
                array_key_exists('verified', $over) ? $over['verified'] : date('c'),
                array_key_exists('approved', $over) ? $over['approved'] : date('c'), $name, $over['locked'] ?? 0]);
        return (int) $this->db->lastInsertId();
    }

    private function client(int $as, array $config = []): TestClient
    {
        $app = new App($config + [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl', 'max_bytes' => 2097152, 'ext' => ['png', 'jpg', 'jpeg', 'webp', 'gif']],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]);
        $app->container->instance(Storage::class,
            new Storage($this->root . '/upl', 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        return (new TestClient($app))->actingAs($as);
    }

    private function send(TestClient $c, string $bytes, string $name = 'x.png', ?string $key = self::KEY_A): \Kip\Http\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($tmp, $bytes);
        return $c->postWithFile('/authorimages/upload', $key === null ? [] : ['upload_key' => $key], 'file',
            ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]);
    }

    private function png(string $pad = ''): string
    {
        return (string) base64_decode(self::PNG) . $pad;
    }

    public function test_author_uploads_into_own_library_and_gets_a_json_path(): void
    {
        $res = $this->send($this->client($this->alice), $this->png());
        $this->assertSame(200, $res->status, $res->body);
        $j = json_decode($res->body, true);
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.png$#D', $j['src']);
        $row = $this->db->one('SELECT owner_id, path, upload_key FROM author_images WHERE id = ?', [$j['id']]);
        $this->assertSame($this->alice, (int) $row['owner_id']);
        $this->assertSame($j['src'], $row['path']);
        $this->assertFileExists($this->root . '/upl/' . basename($j['src']));
    }

    public function test_retry_with_the_same_key_returns_the_same_file_even_when_the_switch_is_off(): void
    {
        $first = json_decode($this->send($this->client($this->alice), $this->png())->body, true);
        \App\Settings::put('chapter_images_on', '0');
        $again = $this->send($this->client($this->alice), $this->png());
        $this->assertSame(200, $again->status);
        $this->assertSame($first, json_decode($again->body, true));
        $this->assertCount(1, glob($this->root . '/upl/*'));
        $this->assertSame(409, $this->send($this->client($this->alice), $this->png('x'))->status, 'same key, different bytes');
        $this->assertCount(1, glob($this->root . '/upl/*'));
    }

    public function test_another_author_with_the_same_key_gets_a_separate_image(): void
    {
        $a = json_decode($this->send($this->client($this->alice), $this->png())->body, true);
        $b = json_decode($this->send($this->client($this->bob), $this->png())->body, true);
        $this->assertNotSame($a['src'], $b['src']);
    }

    public function test_switch_off_refuses_new_uploads(): void
    {
        \App\Settings::put('chapter_images_on', '0');
        $this->assertSame(403, $this->send($this->client($this->alice), $this->png())->status);
        \App\Settings::put('chapter_images_on', '1');
        \App\Settings::put('uploads_on', '0');
        $this->assertSame(403, $this->send($this->client($this->alice), $this->png())->status);
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
    }

    public function test_bad_bytes_svg_and_oversize_are_refused_without_a_row(): void
    {
        $c = $this->client($this->alice);
        $this->assertSame(422, $this->send($c, '<?php echo 1;', 'x.png')->status, 'text claiming png');
        $this->assertSame(422, $this->send($c, '<svg xmlns="http://www.w3.org/2000/svg"/>', 'x.svg')->status);
        $this->assertSame(422, $this->send($c, $this->png(str_repeat('0', 2097152)))->status, 'over 2 MiB');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
    }

    public function test_magic_bytes_for_each_allowed_type(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'mg');
        foreach (["\x89PNG\r\n\x1a\n....", "\xff\xd8\xff\xe0....", 'GIF89a....', 'GIF87a....', 'RIFF1234WEBP'] as $head) {
            file_put_contents($f, $head);
            $this->assertTrue(\App\Features\Authorimages\AuthorimagesController::hasImageMagic($f), bin2hex($head));
        }
        foreach (['RIFF1234WAVE', 'MZ......', '<svg>'] as $head) {
            file_put_contents($f, $head);
            $this->assertFalse(\App\Features\Authorimages\AuthorimagesController::hasImageMagic($f), $head);
        }
    }

    public function test_unapproved_and_locked_accounts_cannot_upload(): void
    {
        $this->assertSame(403, $this->send($this->client($this->user('carl', ['approved' => null])), $this->png())->status);
        $this->assertSame(403, $this->send($this->client($this->user('dana', ['locked' => 1])), $this->png())->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
    }

    public function test_library_cap_is_100(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, created_at) VALUES (?, ?, ?, ?)',
                [$this->alice, sprintf('/uploads/%016x.png', $i), 'x', date('c')]);
        }
        $this->assertSame(422, $this->send($this->client($this->alice), $this->png(), 'x.png', '22222222-2222-4222-8222-222222222222')->status);
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
    }

    public function test_storage_cap_refusal_leaves_no_row_or_file(): void
    {
        $res = $this->send($this->client($this->alice, ['storage_cap_bytes' => 10]), $this->png());
        $this->assertSame(422, $res->status);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
    }

    public function test_catalog_failure_retires_the_file_and_refunds_quota(): void
    {
        $this->db->query('DROP INDEX idx_author_images_owner');
        $this->db->query('ALTER TABLE author_images RENAME TO author_images_x');
        $this->db->query('CREATE TABLE author_images (id INTEGER PRIMARY KEY, owner_id INTEGER, path TEXT, content_sha256 TEXT, upload_key TEXT, created_at TEXT, CHECK (id < 0))');
        $res = $this->send($this->client($this->alice, ['storage_cap_bytes' => 100000]), $this->png());
        $this->assertSame(422, $res->status);
        $this->assertSame([], glob($this->root . '/upl/*') ?: [], 'file retired');
        $this->assertSame(0, (new \App\StorageQuota($this->db, $this->root . '/upl', 100000))->used(), 'bytes refunded');
    }

    public function test_the_admin_library_cannot_delete_a_catalogued_file(): void
    {
        $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, created_at) VALUES (?, ?, ?, ?)', [$this->alice, '/uploads/aaaaaaaaaaaaaaaa.png', 'x', date('c')]);
        $this->assertArrayHasKey('aaaaaaaaaaaaaaaa.png', \App\UploadRefs::names($this->db));
    }

    public function test_a_post_without_a_token_is_refused(): void
    {
        $this->assertSame(403, $this->client($this->alice)->post('/authorimages/upload', [])->status);
    }

    // Regression: ISSUE-001
    // Found by /qa on 2026-11-18
    // Report: .gstack/qa-reports/ (member delete scrubs the row, so the cascade never fires)
    public function test_admin_delete_with_removed_stories_retires_images_files_and_save_keys(): void
    {
        $admin = $this->user('boss'); $this->db->query("UPDATE users SET role = 'admin' WHERE id = ?", [$admin]);
        $j = json_decode($this->send($this->client($this->alice), $this->png())->body, true);
        $this->seedSaveRequest($this->alice);
        $res = $this->client($admin)->postWithToken('/adminmembers/destroy/' . $this->alice, ['stories' => 'remove']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images WHERE owner_id = ?', [$this->alice])['n']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapter_save_requests WHERE user_id = ?', [$this->alice])['n']);
        $this->assertFileDoesNotExist($this->root . '/upl/' . basename($j['src']));
    }

    public function test_admin_delete_with_an_heir_hands_the_library_to_the_heir_and_keeps_the_file(): void
    {
        $admin = $this->user('boss'); $this->db->query("UPDATE users SET role = 'admin' WHERE id = ?", [$admin]);
        $j = json_decode($this->send($this->client($this->alice), $this->png())->body, true);
        $this->seedSaveRequest($this->alice);
        $res = $this->client($admin)->postWithToken('/adminmembers/destroy/' . $this->alice, ['stories' => 'move', 'penname' => 'bob']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame($this->bob, (int) $this->db->one('SELECT owner_id FROM author_images WHERE id = ?', [$j['id']])['owner_id']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapter_save_requests WHERE user_id = ?', [$this->alice])['n']);
        $this->assertFileExists($this->root . '/upl/' . basename($j['src']));
    }

    private function seedSaveRequest(int $uid): void
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, ?, ?, 1, 1, 1, 1)', ['T', 't-' . $uid, 's', $uid]);
        $sid = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, word_count) VALUES (?, 1, 'c', 'b', 1)", [$sid]);
        $this->db->query("INSERT INTO chapter_save_requests (user_id, save_key, request_hash, chapter_id, operation, redirect, created_at) VALUES (?, 'k1', 'h', ?, 'update', '/x', ?)",
            [$uid, (int) $this->db->lastInsertId(), date('c')]);
    }
}
