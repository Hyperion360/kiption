<?php // app/Features/Manage/Tests/ConfirmStepTest.php
namespace App\Features\Manage\Tests;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** An action that cannot be undone asks first, on a page of its own, when
 *  the browser's script did not already ask. */
final class ConfirmStepTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-confirm-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-confirm-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $ins = 'INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($ins, ['ma@e.test', $hash, 'mmadmin', 'admin', 1, date('c'), date('c'), 'mmadmin']);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query($ins, ['mm@e.test', $hash, 'mmmember', 'member', 0, date('c'), date('c'), 'mmmember']);
        $this->memberId = (int) $this->db->lastInsertId();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\Maintenance::reset();
        \App\Features\Wordfilter\WordFilter::forget();
        Lang::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(array $extra = []): array
    {
        return \App\Settings::apply($extra + [
            'env' => 'prod',
            'views' => dirname(__DIR__, 3) . '/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example', 'ui_lang' => 'en',
            'nav_file' => $this->root . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 2),
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
            'site_text_dir' => $this->root . '/site-text',
        ]);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Lang::useSiteText($this->root . '/site-text');
        $c = new TestClient(new App($this->config($extra)));
        return $as === null ? $c : $c->actingAs($as);
    }

    private function navLink(): int
    {
        $this->db->query("INSERT INTO nav_links (label, url, position, is_hidden) VALUES ('Rules', '/page/view/rules', 9, 0)");
        return (int) $this->db->lastInsertId();
    }

    private function applicant(): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['new@e.test', 'x', 'newcomer', 'member', 0, date('c'), 'newcomer']);
        return (int) $this->db->lastInsertId();
    }

    public function test_without_scripting_the_first_post_asks_and_changes_nothing(): void
    {
        $id = $this->navLink();
        $c = $this->client($this->adminId);
        $ask = $c->postWithToken('/nav/delete/' . $id);
        $this->assertSame(200, $ask->status);
        $this->assertStringContainsString(Lang::t('navlinks.confirm_delete'), $ask->body);
        $this->assertStringContainsString('<form method="post" action="/nav/delete/' . $id . '"', $ask->body);
        $this->assertStringContainsString('<input type="hidden" name="confirmed" value="1">', $ask->body);
        $this->assertStringContainsString('href="/nav">' . Lang::t('admin.confirm_cancel') . '</a>', $ask->body);
        $this->assertNotNull($this->db->one('SELECT id FROM nav_links WHERE id = ?', [$id]), 'asking deletes nothing');

        $done = $c->postWithToken('/nav/delete/' . $id, ['confirmed' => '1']);
        $this->assertSame('/nav?saved=removed', $done->headers['Location'] ?? '');
        $this->assertNull($this->db->one('SELECT id FROM nav_links WHERE id = ?', [$id]));
    }

    public function test_nobody_new_sees_the_question(): void
    {
        $id = $this->navLink();
        $member = $this->client($this->memberId)->postWithToken('/nav/delete/' . $id);
        $this->assertSame(403, $member->status);
        $this->assertStringNotContainsString(Lang::t('navlinks.confirm_delete'), $member->body);
        $noToken = $this->client($this->adminId)->post('/nav/delete/' . $id, ['confirmed' => '1']);
        $this->assertNotSame(302, $noToken->status, 'the confirm flag is not a way round the token');
        $this->assertNotNull($this->db->one('SELECT id FROM nav_links WHERE id = ?', [$id]));
        $queue = $this->client($this->memberId)->postWithToken('/queue/member/' . $this->applicant() . '/reject');
        $this->assertSame(403, $queue->status);
    }

    public function test_the_question_page_carries_the_typed_reason_safely(): void
    {
        $id = $this->applicant();
        $c = $this->client($this->adminId);
        $ask = $c->postWithToken('/queue/member/' . $id . '/reject', ['reason' => 'No "<b>bots</b>" here']);
        $this->assertSame(200, $ask->status);
        $this->assertStringContainsString(Lang::t('queue.confirm_reject'), $ask->body);
        $this->assertStringContainsString('name="reason" value="No &quot;&lt;b&gt;bots&lt;/b&gt;&quot; here"', $ask->body);
        $this->assertStringNotContainsString('<b>bots', $ask->body);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked']);

        $c->postWithToken('/queue/member/' . $id . '/reject', ['confirmed' => '1', 'reason' => 'No bots']);
        $this->assertSame(1, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked']);
    }

    public function test_approving_never_asks(): void
    {
        $id = $this->applicant();
        $res = $this->client($this->adminId)->postWithToken('/queue/member/' . $id . '/approve');
        $this->assertSame('/queue?saved=approved', $res->headers['Location'] ?? '');
    }

    public function test_every_form_that_asks_in_the_browser_is_asked_again_by_the_server(): void
    {
        $features = dirname(__DIR__, 2);
        foreach (glob($features . '/*/views/*.php') ?: [] as $view) {
            if (!str_contains((string) file_get_contents($view), 'data-confirm=')) continue;
            $controllers = glob(dirname($view, 2) . '/*Controller.php') ?: [];
            $asks = array_filter($controllers, static fn (string $f): bool => str_contains((string) file_get_contents($f), 'Confirm::given('));
            $this->assertNotEmpty($asks, $view . ' asks in the browser only');
        }
    }
}
