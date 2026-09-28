<?php // tests/ImagesTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Task 8: the admin image library. The library IS the uploads dir (Storage's
 *  hex names are the catalog; no DB rows anywhere), so the listing merges a
 *  glob with ONE UNION query of avatar + cover references for the in-use set,
 *  and deletes refuse (409) anything still referenced. Finding 6: the default
 *  move_uploaded_file mover rejects non-SAPI files, so the upload tests swap
 *  Storage's copy-mover seam on the App's container BEFORE POSTing (the
 *  AccountTest idiom); the controller resolves Storage from the container,
 *  not inline, so the seam takes. */
final class ImagesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private ?App $app = null;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-images-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-images-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // admin + member fixtures, the AdminToolsTest idiom
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('imgadmin@e.test', ?, 'imgadmin', 'admin', 1, ?, ?, 'imgadmin')",
            [$hash, date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('imgmember@e.test', ?, 'imgmember', ?, ?, 'imgmember')",
            [$hash, date('c'), date('c')]);
        $this->memberUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    private function memberId(): int
    {
        return $this->memberUserId;
    }

    /** CLI test runs have no SAPI upload: land the bytes in a temp .png the
     *  copy-mover seam can then place. */
    private function writeTmp(string $bytes, string $ext = 'png'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.' . $ext;
        file_put_contents($tmp, $bytes);
        return $tmp;
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl', 'max_bytes' => 2097152, 'ext' => ['png', 'jpg', 'jpeg', 'webp', 'gif']],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]);
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_admin_uploads_lists_and_deletes_images(): void
    {
        $admin = $this->client($this->adminId());
        // Finding 6: swap the copy-mover seam BEFORE uploading; the controller
        // resolves Storage from the container so this instance serves the POST.
        $dir = $this->root . '/upl';
        $this->app->container->instance(Storage::class,
            new Storage($dir, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $res = $admin->postWithFile('/images/upload', [], 'file',
            ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $this->writeTmp($png), 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)]);
        $this->assertSame(302, $res->status, $res->body);
        $files = glob($dir . '/*') ?: [];
        $this->assertCount(1, $files, 'the upload landed in the library dir');
        $name = basename($files[0]);
        $this->assertMatchesRegularExpression('#^[0-9a-f]{16}\.png$#', $name, 'Storage hex names are the catalog');
        // the library never writes DB rows (the recorded design)
        $this->assertNull($this->db()->one('SELECT avatar_path FROM users WHERE id = ?', [$this->adminId()])['avatar_path']);
        // the listing: name, human size, total count, and a delete form for the orphan
        $list = $admin->get('/images')->body;
        $this->assertStringContainsString($name, $list);
        $this->assertStringContainsString(strlen($png) . ' B', $list, 'human size renders');
        $this->assertStringContainsString('1 file', $list, 'the total count renders');
        $this->assertStringContainsString('value="' . $name . '"', $list, 'an orphan carries a delete form');
        // in-use via an avatar reference: marker renders, no delete form, delete refuses
        $db = $this->db();
        $db->query('UPDATE users SET avatar_path = ? WHERE id = ?', ['/uploads/' . $name, $this->adminId()]);
        $list = $admin->get('/images')->body;
        $this->assertStringContainsString('In use', $list, 'the in-use marker renders');
        $this->assertStringNotContainsString('value="' . $name . '"', $list, 'an in-use file has no delete form');
        $this->assertSame(409, $admin->postWithToken('/images/delete', ['name' => $name])->status, 'avatar-referenced file refuses deletion');
        $this->assertFileExists($dir . '/' . $name);
        // in-use via a cover reference too (the UNION's second arm)
        $db->query('UPDATE users SET avatar_path = NULL WHERE id = ?', [$this->adminId()]);
        $db->query("UPDATE stories SET cover_path = ? WHERE slug = 'the-rabbit-hole'", ['/uploads/' . $name]);
        $this->assertSame(409, $admin->postWithToken('/images/delete', ['name' => $name])->status, 'cover-referenced file refuses deletion');
        $this->assertFileExists($dir . '/' . $name);
        // reference cleared: deletion removes the file
        $db->query("UPDATE stories SET cover_path = NULL WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(302, $admin->postWithToken('/images/delete', ['name' => $name])->status);
        $this->assertFileDoesNotExist($dir . '/' . $name, 'the orphan unlink removed the file');
        // basename confinement: any path separator is a 422, never an unlink target
        $this->assertSame(422, $admin->postWithToken('/images/delete', ['name' => '../' . $name])->status);
        $this->assertSame(422, $admin->postWithToken('/images/delete', ['name' => '..\\' . $name])->status);
        $this->assertSame(422, $admin->postWithToken('/images/delete', ['name' => ''])->status, 'empty name is not a file');
        // unknown names 404, not a silent redirect
        $this->assertSame(404, $admin->postWithToken('/images/delete', ['name' => 'deadbeefdeadbeef.png'])->status);
    }

    public function test_upload_rejections_render_422(): void
    {
        $admin = $this->client($this->adminId());
        $dir = $this->root . '/upl';
        $this->app->container->instance(Storage::class,
            new Storage($dir, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn (string $t, string $d): bool => copy($t, $d)));
        // content does not match the claimed extension (Storage's finfo chain)
        $res = $admin->postWithFile('/images/upload', [], 'file',
            ['name' => 'evil.png', 'type' => 'image/png', 'tmp_name' => $this->writeTmp('<?php echo "not a png";'), 'error' => UPLOAD_ERR_OK, 'size' => 21]);
        $this->assertSame(422, $res->status);
        // no file at all
        $this->assertSame(422, $admin->postWithToken('/images/upload', [])->status);
        $this->assertSame([], glob($dir . '/*') ?: [], 'nothing stored on a rejection');
    }

    public function test_library_is_admin_only(): void
    {
        $this->assertSame(302, $this->client()->get('/images')->status, 'auth redirect');
        $this->assertSame('/auth/login', $this->client()->get('/images')->headers['Location']);
        $this->assertSame(403, $this->client($this->memberId())->get('/images')->status);
        // tokenless POSTs hit the CSRF-first 403 before the admin gate
        $this->assertSame(403, $this->client($this->memberId())->post('/images/upload', ['x' => 1])->status);
        $this->assertSame(403, $this->client($this->memberId())->post('/images/delete', ['name' => 'x.png'])->status);
        // a member WITH a token still meets the admin gate
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/images/upload', ['x' => 1])->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/images/delete', ['name' => 'x.png'])->status);
    }

    public function test_listing_formats_kib_and_mib_tiers(): void
    {
        // QA 10a: humanSize's KiB and MiB arms (the tiny PNG the upload tests
        // store only ever exercises the B tier). The dir IS the catalog, so
        // fixture files land directly in it; both sizes stay under the 2 MiB
        // cap a real upload could have stored.
        $dir = $this->root . '/upl';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/aaaaaaaaaaaaaaaa.png', str_repeat('x', 2048));
        file_put_contents($dir . '/bbbbbbbbbbbbbbbb.png', str_repeat('x', 1572864));
        $list = $this->client($this->adminId())->get('/images')->body;
        $this->assertStringContainsString('2 KiB', $list, 'the KiB tier renders');
        $this->assertStringContainsString('1.5 MiB', $list, 'the MiB tier renders');
        $this->assertStringContainsString('2 files', $list);
        $this->assertStringContainsString(round((2048 + 1572864) / 1048576, 1) . ' MiB', $list, 'the total renders in the top tier');
    }
}
