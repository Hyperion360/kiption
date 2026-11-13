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
        $this->assertStringNotContainsString('newcomer', $c->get('/queue')->body, 'a rejected applicant leaves the queue');
        $this->assertStringNotContainsString('newcomer', $c->get('/manage')->body);
    }

    /** Every route that asks: the first post shows the question and the
     *  thing is still there afterwards. */
    public function test_each_destructive_route_asks_before_it_acts(): void
    {
        $c = $this->client($this->adminId);

        $this->db->query('INSERT INTO invites (code, created_by) VALUES (?, ?)', ['abc123', $this->adminId]);
        $invite = (int) $this->db->lastInsertId();
        $this->assertAsks($c->postWithToken('/invites/revoke/' . $invite), 'invites.confirm_revoke');
        $this->assertNotNull($this->db->one('SELECT id FROM invites WHERE id = ?', [$invite]));

        mkdir($this->root . '/upl', 0775, true);
        file_put_contents($this->root . '/upl/keep.png', 'x');
        $this->assertAsks($c->postWithToken('/images/delete', ['name' => 'keep.png']), 'images.confirm_delete');
        $this->assertFileExists($this->root . '/upl/keep.png');

        $this->assertSame(302, $c->postWithToken('/text/addlanguage', ['code' => 'fr', 'name' => 'Francais', 'dir' => 'ltr'])->status);
        $this->assertAsks($c->postWithToken('/text/removelanguage', ['code' => 'fr']), 'text.confirm_remove_language');
        $this->assertFileExists($this->root . '/site-text/fr.php');

        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated) VALUES (?, ?, ?, ?, ?, 0)',
            ['Pending Work', 'pending-work', 'S.', $this->memberId, 2]);
        $story = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, 'One', 'Body.', 0, 1)", [$story]);
        $chapter = (int) $this->db->lastInsertId();
        $this->assertAsks($c->postWithToken('/queue/chapter/' . $chapter . '/remove'), 'queue.confirm_remove');
        $this->assertAsks($c->postWithToken('/queue/story/' . $story . '/remove'), 'queue.confirm_remove');
        $this->assertNotNull($this->db->one('SELECT id FROM stories WHERE id = ? AND deleted_at IS NULL', [$story]));
        $this->assertNotNull($this->db->one('SELECT id FROM chapters WHERE id = ?', [$chapter]));
    }

    private function assertAsks(\Kip\Http\Response $r, string $question): void
    {
        $this->assertSame(200, $r->status, $question);
        $this->assertStringContainsString(Lang::t($question), $r->body);
        $this->assertStringContainsString('name="confirmed" value="1"', $r->body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notAYes')]
    public function test_only_an_exact_one_counts_as_confirming(mixed $value): void
    {
        $id = $this->navLink();
        $res = $this->client($this->adminId)->postWithToken('/nav/delete/' . $id, ['confirmed' => $value]);
        $this->assertSame(200, $res->status, 'still asks');
        $this->assertNotNull($this->db->one('SELECT id FROM nav_links WHERE id = ?', [$id]), 'and nothing is removed');
    }

    public static function notAYes(): array
    {
        return ['zero' => ['0'], 'true' => ['true'], 'yes' => ['yes'], 'two' => ['2'], 'padded' => [' 1'], 'empty' => [''], 'a list' => [['1']]];
    }

    public function test_a_moderator_is_asked_before_removing_from_the_queue_but_is_barred_from_owner_only_deletes(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('mod@e.test', 'x', 'mmmod', 'moderator', 0, ?, ?, 'mmmod')", [date('c'), date('c')]);
        $mod = $this->client((int) $this->db->lastInsertId());
        $id = $this->applicant();
        $this->assertAsks($mod->postWithToken('/queue/member/' . $id . '/reject'), 'queue.confirm_reject');
        $this->assertSame(0, (int) $this->db->one('SELECT is_locked FROM users WHERE id = ?', [$id])['is_locked']);

        // Owner-only: a moderator gets a refusal, not the question, and confirming changes nothing.
        $link = $this->navLink();
        foreach ([[], ['confirmed' => '1']] as $post) {
            $res = $mod->postWithToken('/nav/delete/' . $link, $post);
            $this->assertSame(403, $res->status);
            $this->assertStringNotContainsString(Lang::t('navlinks.confirm_delete'), $res->body);
        }
        $this->assertNotNull($this->db->one('SELECT id FROM nav_links WHERE id = ?', [$link]));
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
