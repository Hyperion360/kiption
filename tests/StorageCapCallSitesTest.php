<?php // tests/StorageCapCallSitesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The upload-bytes cap at every upload call site (Task C2): the admin image
 *  library, member avatars, story covers, series covers, and the EPUB cover
 *  import. At or over the cap the upload is refused before Storage::put runs,
 *  the file set is unchanged, and nothing is ever deleted. Under the cap,
 *  every path behaves exactly as it did before. */
final class StorageCapCallSitesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private ?App $app = null;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-capsites-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-capsites-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        mkdir($this->root . '/upl', 0775, true); // present before the first put: some tests seed files directly
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('capadmin@e.test', ?, 'capadmin', 'admin', 1, ?, ?, 'capadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('capmember@e.test', ?, 'capmember', ?, ?, 'capmember')",
            [$hash, date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count) VALUES (?, ?, ?, 1, 1, 1, 1, 100)',
            ['A Capped Story', 'a-capped-story', 'One.']);
        $this->db->query("INSERT INTO series (title, slug, summary, owner_id, membership) VALUES ('A Capped Series', 'a-capped-series', 'Two.', 1, 'open')");
    }

    protected function tearDown(): void
    {
        unset($this->app);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function quota(): \App\StorageQuota
    {
        return new \App\StorageQuota($this->db, $this->uploads(), $this->cap);
    }

    private int $cap = 0;

    private function uploads(): string
    {
        return $this->root . '/upl';
    }

    private function client(?int $as, int $cap = 0): TestClient
    {
        $this->cap = $cap;
        $this->app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads(), 'max_bytes' => 2097152, 'ext' => ['png', 'jpg', 'jpeg', 'webp', 'gif']],
            'storage_cap_bytes' => $cap,
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]);
        // CLI tests have no SAPI upload: swap the copy-mover seam before any POST.
        $this->app->container->instance(Storage::class,
            new Storage($this->uploads(), 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    /** A real PNG (finfo passes), with its byte size. */
    private function pngFile(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cap') . '.png';
        file_put_contents($tmp, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return ['name' => 'pic.png', 'type' => 'image/png', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    private function filesOnDisk(): int
    {
        return count(glob($this->uploads() . '/*') ?: []);
    }

    public function test_an_upload_at_the_cap_is_refused_before_anything_is_stored(): void
    {
        $admin = $this->client($this->adminId, cap: 100);
        $ok = $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $this->assertSame(302, $ok->status, $ok->body);
        $bytes = $this->quota()->used();
        $this->assertSame(1, $this->filesOnDisk());
        $refused = $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString(\App\Lang::t('images.storage_full'), $refused->body);
        $this->assertSame(1, $this->filesOnDisk(), 'the refused upload stored nothing');
        $this->assertSame($bytes, $this->quota()->used());
    }

    public function test_an_upload_under_the_cap_is_unchanged(): void
    {
        $admin = $this->client($this->adminId, cap: 1024 * 1024);
        $ok = $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $this->assertSame(302, $ok->status, $ok->body);
        $this->assertSame(1, $this->filesOnDisk());
    }

    public function test_an_admin_delete_decrements_the_counter(): void
    {
        $admin = $this->client($this->adminId, cap: 1024 * 1024);
        $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $before = $this->quota()->used();
        $this->assertGreaterThan(0, $before);
        $name = basename((string) glob($this->uploads() . '/*.png')[0]);
        $res = $admin->postWithToken('/images/delete', ['name' => $name, 'confirmed' => '1']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, $this->quota()->used());
    }

    public function test_an_avatar_at_the_member_limit_refuses_beside_the_field(): void
    {
        $member = $this->client($this->memberId, cap: 50); // member limit 47; the png alone is over it
        $res = $member->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $this->assertSame(422, $res->status, $res->body);
        $this->assertStringContainsString(\App\Lang::t('images.storage_full'), $res->body);
        $this->assertStringContainsString('id="avatar-error"', $res->body, 'the refusal renders beside the avatar field');
        $this->assertNull($this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$this->memberId])['avatar_path']);
        $this->assertSame(0, $this->filesOnDisk());
    }

    public function test_a_replaced_avatar_does_not_grow_usage(): void
    {
        $member = $this->client($this->memberId, cap: 10000);
        $member->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $first = (string) $this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$this->memberId])['avatar_path'];
        $member->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $second = (string) $this->db->one('SELECT avatar_path FROM users WHERE id = ?', [$this->memberId])['avatar_path'];
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($this->uploads() . '/' . basename($first), 'the replaced avatar file is gone');
        $this->assertSame($this->quota()->used(), (int) filesize($this->uploads() . '/' . basename($second)), 'usage is the last avatar only');
    }

    public function test_a_member_upload_cannot_use_the_admin_reserve(): void
    {
        // The reserve arithmetic through real endpoints: the member limit is
        // 95 percent of the cap; the admin may land exactly on the cap.
        $size = (int) filesize($this->pngFile()['tmp_name']);
        $member = $this->client($this->memberId, cap: 2000);
        $q = $this->quota();
        $q->used(); // seed the row
        // The counter sits exactly one member-upload short of the member limit,
        // but exactly one admin-upload short of the full cap.
        $q->db()->query('UPDATE storage_usage SET used = ? WHERE id = 1', [2000 - $size]);
        $refused = $member->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $this->assertSame(422, $refused->status, 'landing at 2000 is over the member limit of 1900');
        $admin = $this->client($this->adminId, cap: 2000);
        $admitted = $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $this->assertSame(302, $admitted->status, $admitted->body);
        $this->assertSame(2000, $this->quota()->used(), 'the admin upload landed exactly at the cap');
        $over = $admin->postWithFile('/images/upload', [], 'file', $this->pngFile());
        $this->assertSame(422, $over->status, 'the next admin upload is refused');
    }

    public function test_a_replaced_story_cover_removes_the_old_file_and_releases_its_bytes(): void
    {
        $author = $this->client(1, cap: 10000); // Demo Author owns the seeded story
        $author->postWithFile('/story/cover/a-capped-story', [], 'cover', $this->pngFile());
        $first = (string) $this->db->one("SELECT cover_path FROM stories WHERE slug = 'a-capped-story'")['cover_path'];
        $this->assertFileExists($this->uploads() . '/' . basename($first));
        $author->postWithFile('/story/cover/a-capped-story', [], 'cover', $this->pngFile());
        $second = (string) $this->db->one("SELECT cover_path FROM stories WHERE slug = 'a-capped-story'")['cover_path'];
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($this->uploads() . '/' . basename($first), 'the replaced cover file is gone');
        $this->assertSame((int) filesize($this->uploads() . '/' . basename($second)), $this->quota()->used());
    }

    public function test_a_cover_still_used_elsewhere_is_not_deleted(): void
    {
        // A second story references the same file: replacing the first
        // story's cover keeps the file and its bytes.
        $shared = $this->uploads() . '/shared.png';
        file_put_contents($shared, 'not really a png, but present on disk');
        $this->db->query("UPDATE stories SET cover_path = '/uploads/shared.png' WHERE slug = 'a-capped-story'");
        $this->db->query("UPDATE stories SET cover_path = '/uploads/shared.png' WHERE slug = 'the-rabbit-hole'");
        $author = $this->client(1, cap: 10000);
        $before = $this->quota()->used();
        $author->postWithFile('/story/cover/a-capped-story', [], 'cover', $this->pngFile());
        $this->assertFileExists($shared, 'the other story still uses it');
        $new = (string) $this->db->one("SELECT cover_path FROM stories WHERE slug = 'a-capped-story'")['cover_path'];
        $expected = $before + (int) filesize($this->uploads() . '/' . basename($new));
        $this->assertSame($expected, $this->quota()->used(), "the shared file's bytes are not released");
    }

    public function test_a_replaced_series_cover_removes_the_old_file_and_releases_its_bytes(): void
    {
        $owner = $this->client(1, cap: 10000);
        $owner->postWithFile('/series/cover/a-capped-series', [], 'cover', $this->pngFile());
        $first = (string) $this->db->one("SELECT cover_path FROM series WHERE slug = 'a-capped-series'")['cover_path'];
        $owner->postWithFile('/series/cover/a-capped-series', [], 'cover', $this->pngFile());
        $second = (string) $this->db->one("SELECT cover_path FROM series WHERE slug = 'a-capped-series'")['cover_path'];
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($this->uploads() . '/' . basename($first));
        $this->assertSame((int) filesize($this->uploads() . '/' . basename($second)), $this->quota()->used());
    }

    public function test_member_cover_uploads_are_throttled_like_avatars(): void
    {
        // The member owns a story, so the cover route answers them.
        $this->db->query('UPDATE stories SET author_id = ? WHERE slug = ?', [$this->memberId, 'a-capped-story']);
        $member = $this->client($this->memberId, cap: 0);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(302, $member->postWithFile('/story/cover/a-capped-story', [], 'cover', $this->pngFile())->status, "post $i");
        }
        $eleventh = $member->postWithFile('/story/cover/a-capped-story', [], 'cover', $this->pngFile());
        $this->assertSame(429, $eleventh->status, 'the eleventh in the window is throttled');
    }

    public function test_member_self_delete_releases_the_avatar_bytes(): void
    {
        $member = $this->client($this->memberId, cap: 10000);
        $member->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $before = $this->quota()->used();
        $this->assertGreaterThan(0, $before);
        $res = $member->postWithToken('/account/destroy', ['current_password' => 'password123', 'stories' => 'keep']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, $this->quota()->used());
    }

    public function test_admin_member_delete_releases_the_avatar_bytes(): void
    {
        $this->client($this->memberId, cap: 10000)->postWithFile('/account/avatar', [], 'avatar', $this->pngFile());
        $before = $this->quota()->used();
        $this->assertGreaterThan(0, $before);
        $admin = $this->client($this->adminId, cap: 10000);
        $res = $admin->postWithToken('/adminmembers/destroy/' . $this->memberId, ['stories' => 'remove']);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(0, $this->quota()->used());
    }

    public function test_the_epub_import_skips_the_cover_and_imports_the_text_at_the_cap(): void
    {
        $uploads = $this->uploads();
        $epub = $this->root . '/book.epub';
        $zip = new \ZipArchive();
        $zip->open($epub, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?>'
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
            . '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>'
            . '</rootfiles></container>');
        $zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?>'
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>Capped Book</dc:title><dc:creator>capmember</dc:creator>'
            . '<dc:language>en</dc:language></metadata>'
            . '<manifest><item id="cover-img" href="cover.png" media-type="image/png" properties="cover-image"/>'
            . '<item id="ch1" href="ch1.xhtml" media-type="application/xhtml+xml"/></manifest>'
            . '<spine><itemref idref="ch1"/></spine></package>');
        $zip->addFromString('OEBPS/cover.png', (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $zip->addFromString('OEBPS/ch1.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>One</title></head><body><p>Prose.</p></body></html>');
        $zip->close();
        $cap = 10; // the cover alone is bigger than the whole cap
        $q = new \App\StorageQuota($this->db, $uploads, $cap);
        $importer = new \App\Import\Epub($this->db, $uploads, quota: $q);
        $importer->importFile($epub, 1, true); // commit as Demo Author (id 1)
        $report = $importer->lastReport();
        $this->assertSame('imported', $report['status'], $report['status'] . ': ' . implode('; ', $report['notes'] ?? []));
        $this->assertContains('cover skipped: storage limit', $report['notes']);
        $this->assertGreaterThan(0, (int) ($report['words'] ?? 0), 'the text still imported');
        $row = $this->db->one("SELECT COUNT(*) c FROM stories WHERE slug LIKE '%capped-book%'")['c'];
        $this->assertSame(1, (int) $row);
    }

    /** Task C4: the image library shows the storage line only when a cap is
     *  set, warns at 90 percent, and replaces the upload form at 100. */
    private function seedUploads(int $n, int $bytes = 100): void
    {
        for ($i = 0; $i < $n; $i++) file_put_contents($this->uploads() . "/seed$i.png", str_repeat('x', $bytes));
    }

    public function test_the_images_page_shows_the_storage_line(): void
    {
        $this->seedUploads(2); // 200 bytes on disk
        $admin = $this->client($this->adminId, cap: 1000);
        $body = $admin->get('/images')->body;
        $used = $this->quota()->used();
        $this->assertSame(200, $used, 'the counter seeds from the seeded files');
        $this->assertStringContainsString(\App\Lang::t('images.storage_used',
            ['used' => \App\StorageQuota::human($used), 'total' => \App\StorageQuota::human(1000)]), $body);
        $this->assertStringNotContainsString(\App\Lang::t('images.storage_warn'), $body, '200 of 1000 is far from the line');
    }

    public function test_the_images_page_warns_at_ninety_percent(): void
    {
        $this->seedUploads(9, 100); // 900 of 1000
        $body = $this->client($this->adminId, cap: 1000)->get('/images')->body;
        $this->assertStringContainsString(\App\Lang::t('images.storage_warn'), $body);
        $this->assertStringContainsString('action="/images/upload"', $body, 'still room, so the form stays');
    }

    public function test_the_images_page_replaces_the_form_at_the_limit(): void
    {
        $this->seedUploads(10, 100); // exactly the cap
        $body = $this->client($this->adminId, cap: 1000)->get('/images')->body;
        $this->assertStringContainsString(\App\Lang::t('images.storage_full'), $body);
        $this->assertStringNotContainsString('action="/images/upload"', $body, 'the upload form is replaced');
    }

    public function test_an_uncapped_images_page_gains_nothing(): void
    {
        $this->seedUploads(2);
        $body = $this->client($this->adminId, cap: 0)->get('/images')->body;
        $this->assertStringNotContainsString(\App\Lang::t('images.storage_used', ['used' => '', 'total' => '']), $body);
        $this->assertStringNotContainsString(\App\Lang::t('images.storage_warn'), $body);
        $this->assertStringContainsString('action="/images/upload"', $body);
    }

    /** Task C3: a full counter never blocks writing. Through the real
     *  controllers, with the counter AT the cap: a story, a chapter, a
     *  review, and a message all succeed. The quota class holds no unlink
     *  (pinned in StorageQuotaTest); the mutation check for this test lives
     *  in its commit notes. */
    public function test_a_full_counter_never_blocks_writing(): void
    {
        $member = $this->client($this->memberId, cap: 1000);
        $q = $this->quota();
        $q->used(); // seed
        $q->db()->query('UPDATE storage_usage SET used = 1000 WHERE id = 1'); // completely full

        $story = $member->postWithToken('/story/create',
            ['title' => 'Written At The Cap', 'summary' => 'Text is not storage.', 'rating_id' => '1', 'categories' => ['1']]);
        $this->assertSame(302, $story->status, $story->body);
        $slug = trim((string) parse_url((string) $story->headers['Location'], PHP_URL_PATH), '/');
        $slug = str_replace('story/edit/', '', $slug);

        $chapter = $member->postWithToken("/chapter/create/$slug",
            ['title' => 'One', 'content' => 'Words, not bytes of uploads.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $chapter->status, $chapter->body);

        $review = $member->postWithToken('/review/add/a-capped-story',
            ['body' => 'A fine read.', 'rating' => '', 'guest_name' => '']);
        $this->assertSame(302, $review->status, $review->body);

        $message = $member->postWithToken('/messages/send/demo-author', ['body' => 'Still writing.']);
        $this->assertSame(302, $message->status, $message->body);

        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM stories WHERE title = 'Written At The Cap'")['c']);
        $this->assertSame(1000, $this->quota()->used(), 'text growth never touches the counter');
    }
}
