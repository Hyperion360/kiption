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
        $this->assertMatchesRegularExpression('#^/uploads/[0-9a-f]{16}\.(png|webp)$#D', $j['src'], 'WebP when GD is present, the original otherwise');
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
        $this->assertSame(422, $this->send($c, $this->png(str_repeat('0', 10485760)))->status, 'over the 10 MiB intake cap');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
    }

    private function needGd(): void
    {
        if (!\Kip\Image::available()) $this->markTestSkipped('GD with WebP is not available');
    }

    /** A noisy truecolor PNG, stored uncompressed so it is far over 2 MiB. */
    private function bigPng(int $w = 1000, int $h = 800): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 4000; $i++) imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
        ob_start();
        imagepng($im, null, 0);
        return (string) ob_get_clean();
    }

    public function test_a_big_png_is_stored_as_a_webp_under_the_stored_cap(): void
    {
        $this->needGd();
        $bytes = $this->bigPng();
        $this->assertGreaterThan(2097152, strlen($bytes));
        $res = $this->send($this->client($this->alice), $bytes);
        $this->assertSame(200, $res->status, $res->body);
        $src = json_decode($res->body, true)['src'];
        $this->assertStringEndsWith('.webp', $src);
        $this->assertLessThan(2097152, filesize($this->root . '/upl/' . basename($src)));
    }

    public function test_the_quota_is_charged_the_stored_webp_not_the_original(): void
    {
        $this->needGd();
        $res = $this->send($this->client($this->alice, ['storage_cap_bytes' => 1000000]), $this->bigPng());
        $this->assertSame(200, $res->status, $res->body);
        $stored = filesize($this->root . '/upl/' . basename(json_decode($res->body, true)['src']));
        $this->assertSame($stored, (new \App\StorageQuota($this->db, $this->root . '/upl', 1000000))->used());
    }

    public function test_a_retry_of_a_big_image_returns_the_same_src_and_a_changed_body_conflicts(): void
    {
        $this->needGd();
        $bytes = $this->bigPng();
        $first = json_decode($this->send($this->client($this->alice), $bytes)->body, true);
        $again = $this->send($this->client($this->alice), $bytes);
        $this->assertSame(200, $again->status);
        $this->assertSame($first, json_decode($again->body, true));
        $this->assertCount(1, glob($this->root . '/upl/*'));
        $this->assertSame(409, $this->send($this->client($this->alice), $bytes . 'x')->status);
        $this->assertCount(1, glob($this->root . '/upl/*'));
    }

    public function test_an_animated_gif_under_the_cap_is_stored_as_a_gif(): void
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
        $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00" . $frame . $frame . ';';
        $res = $this->send($this->client($this->alice), $gif, 'a.gif');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringEndsWith('.gif', json_decode($res->body, true)['src']);
    }

    public function test_a_forged_animated_gif_over_the_pixel_cap_says_too_large_not_wrong_type(): void
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
        $res = $this->send($this->client($this->alice), "GIF89a\xFF\xFF\xFF\xFF\x00\x00\x00" . $frame . $frame . ';', 'a.gif');
        $this->assertSame(422, $res->status);
        $this->assertSame(\App\Lang::t('authorimages.too_big'), json_decode($res->body, true)['error']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
    }

    public function test_a_file_that_passes_the_magic_check_but_will_not_decode_is_refused_and_leaves_nothing(): void
    {
        $this->needGd();
        $before = (new \App\StorageQuota($this->db, $this->root . '/upl', 100000))->used();
        $res = $this->send($this->client($this->alice, ['storage_cap_bytes' => 100000]), "\x89PNG\r\n\x1a\n" . str_repeat("\0", 80));
        $this->assertSame(422, $res->status);
        $this->assertSame($before, (new \App\StorageQuota($this->db, $this->root . '/upl', 100000))->used());
        $this->assertSame([], glob($this->root . '/upl/*') ?: []);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
    }

    public function test_a_webp_refused_at_the_storage_cap_leaves_no_temp_file(): void
    {
        $this->needGd();
        $c = $this->client($this->alice, ['storage_cap_bytes' => 10]);
        $before = glob(sys_get_temp_dir() . '/img*.webp') ?: [];
        $this->assertSame(422, $this->send($c, $this->bigPng(400, 300))->status);
        $this->assertSame($before, glob(sys_get_temp_dir() . '/img*.webp') ?: []);
    }

    public function test_one_author_cannot_reference_anothers_image_and_owner_id_is_never_taken_from_the_request(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        file_put_contents($tmp, $this->png());
        $res = $this->client($this->alice)->postWithFile('/authorimages/upload', ['upload_key' => self::KEY_A, 'owner_id' => (string) $this->bob], 'file',
            ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
        $this->assertSame(200, $res->status, $res->body);
        $j = json_decode($res->body, true);
        $this->assertSame($this->alice, (int) $this->db->one('SELECT owner_id FROM author_images WHERE id = ?', [$j['id']])['owner_id']);
        $md = '![a picture](' . $j['src'] . ')';
        $this->assertNull(\App\ChapterImages::refusal($this->db, $this->alice, '', $md, true));
        $this->assertSame('chapter.err_image_library', \App\ChapterImages::refusal($this->db, $this->bob, '', $md, true));
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

    /** @return array{id:int,src:string} */
    private function uploaded(int $as): array
    {
        return json_decode($this->send($this->client($as), $this->png(), 'x.png', null)->body, true);
    }

    private function chapterShowing(string $src, int $author, int $draft = 0, ?string $publishAt = null, bool $storyGone = false): void
    {
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, deleted_at) VALUES (?, ?, ?, ?, 1, 1, 1, 1, ?)',
            ['Story ' . $src, 's' . uniqid(), 's', $author, $storyGone ? date('c') : null]);
        $sid = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, word_count, is_draft, publish_at) VALUES (?, 1, 'c', ?, 1, ?, ?)",
            [$sid, "Words.\n\n![A map]($src)", $draft, $publishAt]);
    }

    public function test_an_author_deletes_an_unused_image_and_the_bytes_come_back(): void
    {
        $c = $this->client($this->alice, ['storage_cap_bytes' => 100000]);
        $j = json_decode($this->send($c, $this->png(), 'x.png', null)->body, true);
        $quota = new \App\StorageQuota($this->db, $this->root . '/upl', 100000);
        $this->assertGreaterThan(0, $quota->used());
        $res = $this->client($this->alice, ['storage_cap_bytes' => 100000])->postWithToken('/authorimages/delete', ['id' => (string) $j['id']]);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
        $this->assertFileDoesNotExist($this->root . '/upl/' . basename($j['src']));
        $this->assertSame(0, $quota->used());
    }

    public function test_an_image_a_chapter_shows_cannot_be_deleted_whatever_the_chapter_state(): void
    {
        $j = $this->uploaded($this->alice);
        foreach ([[0, null], [1, null], [0, '2999-01-01T00:00:00Z']] as $i => [$draft, $at]) {
            $this->db->query('DELETE FROM stories');
            $this->chapterShowing($j['src'], $this->alice, $draft, $at);
            $res = $this->client($this->alice)->postWithToken('/authorimages/delete', ['id' => (string) $j['id']]);
            $this->assertSame(409, $res->status, "case $i " . $res->body);
            $this->assertStringContainsString('Story ' . $j['src'], json_decode($res->body, true)['error']);
            $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
            $this->assertFileExists($this->root . '/upl/' . basename($j['src']));
        }
    }

    public function test_one_author_cannot_delete_or_probe_anothers_image(): void
    {
        $j = $this->uploaded($this->alice);
        $this->chapterShowing($j['src'], $this->alice);
        $res = $this->client($this->bob)->postWithToken('/authorimages/delete', ['id' => (string) $j['id']]);
        $this->assertSame(404, $res->status);
        $this->assertStringNotContainsString('Story', $res->body, 'no story title leaks');
        $this->assertSame(404, $this->client($this->bob)->postWithToken('/authorimages/delete', ['id' => '999999'])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
    }

    public function test_deleting_an_image_needs_a_token_and_a_signed_in_author(): void
    {
        $j = $this->uploaded($this->alice);
        $this->assertSame(403, $this->client($this->alice)->post('/authorimages/delete', ['id' => (string) $j['id']])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) AS n FROM author_images')['n']);
    }

    public function test_the_admin_library_will_not_delete_a_file_a_chapter_still_shows(): void
    {
        $admin = $this->user('boss'); $this->db->query("UPDATE users SET role = 'admin' WHERE id = ?", [$admin]);
        $name = 'bbbbbbbbbbbbbbbb.png';
        mkdir($this->root . '/upl', 0775, true);
        file_put_contents($this->root . '/upl/' . $name, $this->png());
        $this->chapterShowing('/uploads/' . $name, $this->alice);
        $res = $this->client($admin)->postWithToken('/images/delete', ['name' => $name, 'confirmed' => '1']);
        $this->assertSame(409, $res->status);
        $this->assertFileExists($this->root . '/upl/' . $name);
        $this->db->query('DELETE FROM stories');
        $this->assertSame(302, $this->client($admin)->postWithToken('/images/delete', ['name' => $name, 'confirmed' => '1'])->status);
        $this->assertFileDoesNotExist($this->root . '/upl/' . $name);
    }

    public function test_chapter_image_use_finds_a_path_in_notes_and_lists_at_most_five(): void
    {
        $src = '/uploads/cccccccccccccccc.png';
        $this->assertSame([], \App\ChapterImageUse::chaptersUsing($this->db, $src));
        for ($i = 0; $i < 7; $i++) $this->chapterShowing($src, $this->alice);
        $this->assertCount(5, \App\ChapterImageUse::chaptersUsing($this->db, $src));
        $this->db->query('DELETE FROM stories');
        $this->chapterShowing('/uploads/x.png', $this->alice);
        $this->db->query('UPDATE chapters SET notes_after = ?', ['![n](' . $src . ')']);
        $this->assertCount(1, \App\ChapterImageUse::chaptersUsing($this->db, $src));
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
