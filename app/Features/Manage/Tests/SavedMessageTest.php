<?php // app/Features/Manage/Tests/SavedMessageTest.php
namespace App\Features\Manage\Tests;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Every admin save answers: the redirect carries a short code and the
 *  screen it lands on prints the matching line. */
final class SavedMessageTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-saved-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-saved-' . uniqid('', true);
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

    public function test_a_save_lands_on_a_screen_that_says_saved(): void
    {
        $c = $this->client($this->adminId);
        $res = $c->postWithToken('/signupcheck/save', ['enabled' => '1', 'question' => 'Two plus two?', 'answer' => 'four']);
        $this->assertSame('/signupcheck?saved=1', $res->headers['Location'] ?? '');
        $body = $c->get('/signupcheck', ['saved' => '1'])->body;
        $this->assertStringContainsString('role="status" data-js-module="saved">' . Lang::t('admin.saved_saved') . '</p>', $body);
        $this->assertSame(1, substr_count($body, 'role="status"'), 'one line, not two');
        $this->assertStringNotContainsString('role="status"', $c->get('/signupcheck')->body, 'no code, no line');
    }

    public function test_the_line_names_what_happened_and_only_from_the_fixed_list(): void
    {
        $c = $this->client($this->adminId);
        $this->assertStringContainsString('>' . Lang::t('admin.saved_removed') . '</p>', $c->get('/tags', ['saved' => 'removed'])->body);
        $this->assertStringContainsString('>' . Lang::t('admin.saved_turned_off') . '</p>', $c->get('/features', ['saved' => 'turned_off'])->body);
        $this->assertStringNotContainsString('role="status"', $c->get('/tags', ['saved' => '<b>hello'])->body, 'the address cannot put its own words on the page');
        $this->assertStringNotContainsString('role="status"', $this->client($this->memberId)->get('/', ['saved' => '1'])->body, 'reading pages never show it');
    }

    public function test_the_code_goes_before_a_fragment_and_after_an_existing_query(): void
    {
        $this->assertSame('/tags?saved=1', \App\Saved::url('/tags'));
        $this->assertSame('/text?lang=fr&saved=added', \App\Saved::url('/text?lang=fr', 'added'));
        $this->assertSame('/queue?saved=approved#members', \App\Saved::url('/queue#members', 'approved'));
        $this->assertSame('/tags?saved=1', \App\Saved::url('/tags', 'nonsense'));
        foreach (\App\Saved::CODES as $key) $this->assertNotSame($key, Lang::t($key), $key);
    }

    /** The list of admin saves is read from the code, so a new screen cannot
     *  quietly go back to a silent redirect. News and pages are left out:
     *  their saves land on public reading pages. */
    public function test_no_admin_screen_redirects_to_its_own_list_without_the_code(): void
    {
        $silent = [];
        foreach (\App\Features\Manage\ManageController::LINKS as $link) {
            $name = explode('/', ltrim($link[1], '/'))[0];
            if (in_array($name, ['news', 'page', 'pages'], true)) continue;
            foreach (glob(dirname(__DIR__, 2) . '/' . ucfirst($name) . '/*Controller.php') ?: [] as $file) {
                if (preg_match_all('#Response::redirect\(\'/' . $name . '[^)]*\)#', (string) file_get_contents($file), $m)) {
                    $silent[] = basename($file) . ': ' . implode(' ', $m[0]);
                }
            }
        }
        $this->assertSame([], $silent);
    }

    public function test_locking_a_member_can_be_taken_back_from_the_line_that_reports_it(): void
    {
        $c = $this->client($this->adminId);
        $to = $c->postWithToken('/adminmembers/lock/' . $this->memberId, [])->headers['Location'] ?? '';
        $this->assertStringStartsWith('/adminmembers?saved=locked&undo=', $to);
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        $body = $c->get('/adminmembers', $query)->body;
        $this->assertStringContainsString(Lang::t('admin.saved_locked'), $body);
        $this->assertStringContainsString('action="/adminmembers/unlock/' . $this->memberId . '" class="inline"', $body);
        $this->assertStringContainsString('class="saved-undo">' . Lang::t('admin.undo') . '</button>', $body);
        $c->postWithToken('/adminmembers/unlock/' . $this->memberId, []);
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$this->memberId])['is_locked']);
    }

    public function test_a_feature_switch_offers_undo_too(): void
    {
        $c = $this->client($this->adminId);
        $to = $c->postWithToken('/features/toggle/news', [])->headers['Location'] ?? '';
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        $this->assertSame('turned_off', $query['saved'] ?? '');
        $this->assertStringContainsString('action="/features/toggle/news" class="inline"', $c->get('/features', $query)->body);
    }

    public function test_an_undo_button_never_comes_from_a_link_someone_else_made(): void
    {
        $c = $this->client($this->adminId);
        $forged = ['saved' => 'unlocked', 'undo' => '/adminmembers/lock/' . $this->memberId, 'undo_key' => str_repeat('a', 32)];
        $body = $c->get('/adminmembers', $forged)->body;
        $this->assertStringContainsString(Lang::t('admin.saved_unlocked'), $body);
        $this->assertStringNotContainsString('saved-undo', $body, 'a wrong signature shows the line and no button');
        $signed = \App\Saved::withUndo('/adminmembers', 'removed', '/adminmembers/delete/' . $this->memberId, $c->csrfToken())->headers['Location'] ?? '';
        $this->assertSame('/adminmembers?saved=removed', $signed, 'only lock, unlock and feature switches can be offered');
    }
}
