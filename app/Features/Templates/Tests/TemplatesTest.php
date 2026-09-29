<?php // app/Features/Templates/Tests/TemplatesTest.php
namespace App\Features\Templates\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private ?App $app = null;
    private int $adminUserId = 0;
    private int $memberUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-tpl-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-tpl-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // admin + member fixtures, the SeriesRepositoryTest idiom
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('tpladmin@e.test', ?, 'tpladmin', 'admin', 1, ?, ?, 'tpladmin')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->adminUserId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('tplmember@e.test', ?, 'tplmember', ?, ?, 'tplmember')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
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

    private function mailLog(): string
    {
        return $this->root . '/mail.log';
    }

    private function adminId(): int
    {
        return $this->adminUserId;
    }

    private function memberId(): int
    {
        return $this->memberUserId;
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog(), 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'nav_file' => $this->root . '/nav.json',
        ]);
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_templates_default_and_override(): void
    {
        $db = $this->db();
        [$s, $b] = \App\Templates::get($db, 'member_verify', 'Verify your account', 'Confirm: {url}');
        $this->assertSame('Verify your account', $s);
        $this->assertSame('Confirm: {url}', $b);
        $db->query('INSERT INTO mail_templates (name, subject, body) VALUES (?,?,?)', ['member_verify', 'Welcome aboard', 'Go to {url} now.']);
        [$s2, $b2] = \App\Templates::get($db, 'member_verify', 'Verify your account', 'Confirm: {url}');
        $this->assertSame('Welcome aboard', $s2);
        $this->assertSame('Go to {url} now.', $b2);
        \App\Templates::seedDefaults($db);
        $this->assertGreaterThan(5, (int) $db->one('SELECT COUNT(*) c FROM mail_templates')['c'], 'seeded rows exist');
        \App\Templates::seedDefaults($db); // idempotent
        $this->assertSame(7, (int) $db->one('SELECT COUNT(*) c FROM mail_templates')['c'],
            'the seven distinct names, the earlier override preserved, no duplicates');
    }

    public function test_call_site_uses_the_template(): void
    {
        $this->db()->query('INSERT INTO mail_templates (name, subject, body) VALUES (?,?,?)', ['password_reset', 'Custom reset', 'Link: {url}']);
        $res = $this->client()->postWithToken('/auth/remind', ['email' => 'demo@example.test']);
        $this->assertSame(200, $res->status, $res->body);
        $mail = (string) file_get_contents($this->mailLog());
        $this->assertStringContainsString('Link: https://archive.example/auth/reset/', $mail);
        $this->assertStringContainsString('Custom reset', $mail);
    }

    public function test_admin_template_editor_lists_edits_and_gates(): void
    {
        $admin = $this->client($this->adminId());
        // gates: guest redirects, member barred (SQL admin check)
        $this->assertSame(302, $this->client()->get('/templates')->status, 'auth redirect');
        $this->assertSame(403, $this->client($this->memberId())->get('/templates')->status);
        // the index seeds the defaults so the editor always has rows
        $index = $admin->get('/templates');
        $this->assertSame(200, $index->status);
        $this->assertStringContainsString('password_reset', $index->body);
        $this->assertStringContainsString('Reset your password', $index->body);
        $this->assertSame(7, (int) $this->db()->one('SELECT COUNT(*) c FROM mail_templates')['c'], 'index seeds the seven names');
        // the editor prefills and documents the placeholder vocabulary
        $edit = $admin->get('/templates/edit/password_reset');
        $this->assertSame(200, $edit->status);
        $this->assertStringContainsString('{url}', $edit->body);
        $this->assertStringContainsString('Reset your password', $edit->body);
        $this->assertSame(404, $admin->get('/templates/edit/unknown')->status);
        // update saves both fields; empty subject 422s; unknown name 404s
        $this->assertSame(302, $admin->postWithToken('/templates/update/password_reset', ['subject' => 'New subject', 'body' => 'Go {url}'])->status);
        $row = $this->db()->one('SELECT subject, body FROM mail_templates WHERE name = ?', ['password_reset']);
        $this->assertSame('New subject', $row['subject']);
        $this->assertSame('Go {url}', $row['body']);
        $this->assertSame(422, $admin->postWithToken('/templates/update/password_reset', ['subject' => '', 'body' => 'x'])->status);
        $this->assertSame(404, $admin->postWithToken('/templates/update/unknown', ['subject' => 'X', 'body' => 'x'])->status);
        $this->assertSame(403, $this->client($this->memberId())->postWithToken('/templates/update/password_reset', ['subject' => 'X', 'body' => 'x'])->status);
    }
}
