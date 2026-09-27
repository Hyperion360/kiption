<?php // tests/MuteTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MuteTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $memberRowId = 0;
    private int $authorRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mute-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-mute-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->authorRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    protected function tearDown(): void
    {
        // The FeaturesTest discipline: any test that inits the flag resolver
        // must drop its state, or a later suite in this process inherits a
        // memo pointing at an unlinking temp DB.
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return $this->memberRowId;
    }

    private function authorId(): int
    {
        return $this->authorRowId;
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-mute-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_mute_toggle_and_the_account_block(): void
    {
        $me = $this->client($this->memberId());
        $this->assertSame(302, $me->postWithToken('/mute/add/demo-author')->status);
        $db = $this->db();
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM muted WHERE user_id = ? AND author_id = ?', [$this->memberId(), $this->authorId()])['c']);
        // the account block lists the muted author with an unmute button
        $body = $me->get('/account')->body;
        $this->assertStringContainsString('Demo Author', $body);
        $this->assertStringContainsString('Unmute', $body);
        // idempotent; toggle off removes
        $me->postWithToken('/mute/add/demo-author');
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM muted')['c']);
        $this->assertSame(302, $me->postWithToken('/mute/remove/demo-author')->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM muted')['c']);
        // self-mute by one's own profile slug 404s, and nothing was written by it
        $this->assertSame(404, $me->postWithToken('/mute/add/betafriend')->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM muted')['c']);
        // a guest gets the login redirect on the gated POST, token or no token
        $this->assertSame(302, $this->client()->postWithToken('/mute/add/demo-author')->status);
        // an unknown penname 404s exactly like the profile page would
        $this->assertSame(404, $me->postWithToken('/mute/add/ghost')->status);
        // muting is silent: no notification kind ever fires, through the whole matrix
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM notifications')['c']);
    }

    public function test_the_mute_button_rides_the_profile_and_the_directory_rows(): void
    {
        $me = $this->client($this->memberId());
        // the profile: the form targets the author's profile slug
        $profile = $me->get('/user/view/demo-author')->body;
        $this->assertStringContainsString('action="/mute/add/demo-author"', $profile);
        $this->assertStringContainsString(\App\Lang::t('mute.button'), $profile);
        // the directory row carries the same button
        $directory = $me->get('/browse/authors')->body;
        $this->assertStringContainsString('action="/mute/add/demo-author"', $directory);
        // guests see neither surface's button: anonymous renders stay unchanged
        $this->assertStringNotContainsString('/mute/add/', $this->client()->get('/user/view/demo-author')->body);
        $this->assertStringNotContainsString('/mute/add/', $this->client()->get('/browse/authors')->body);
        // one's own profile shows no self-mute button
        $this->assertStringNotContainsString('/mute/add/', $me->get('/user/view/betafriend')->body);
        // the account block headers the section only when a mute exists or not: the
        // empty state renders for a fresh member
        $this->assertStringContainsString(\App\Lang::t('account.muted'), $me->get('/account')->body);
    }

    /** The anti-join fragment has ONE home; every viewer-gated listing in
     *  Task 2 appends this exact string and binds its viewer id at the ?'s
     *  text position. The pin keeps the shape from drifting between the
     *  seven call sites, and the alias guard keeps the interpolation closed
     *  to plain identifiers. */
    public function test_the_clause_fragment_is_the_one_home(): void
    {
        $this->assertSame(
            ' AND NOT EXISTS (SELECT 1 FROM muted mu WHERE mu.user_id = ? AND mu.author_id = s.author_id)',
            \App\Repositories\MuteRepository::clause()
        );
        $this->assertSame(
            ' AND NOT EXISTS (SELECT 1 FROM muted mu WHERE mu.user_id = ? AND mu.author_id = si.author_id)',
            \App\Repositories\MuteRepository::clause('si')
        );
        $this->expectException(\InvalidArgumentException::class);
        \App\Repositories\MuteRepository::clause('s.author_id; DROP TABLE stories');
    }

    public function test_mute_flag_off_404s_the_toggles_and_hides_the_surfaces(): void
    {
        $db = $this->db();
        $db->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['mute']);
        \App\Features::init($db, []); // the public/index.php init, pointed at this throwaway DB
        $me = $this->client($this->memberId());
        // both verbs 404 before any write, flag-first
        $this->assertSame(404, $me->postWithToken('/mute/add/demo-author')->status);
        $this->assertSame(404, $me->postWithToken('/mute/remove/demo-author')->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM muted')['c']);
        // the button hides on both surfaces, the account block included
        $this->assertStringNotContainsString('/mute/add/', $me->get('/user/view/demo-author')->body);
        $this->assertStringNotContainsString('/mute/add/', $me->get('/browse/authors')->body);
        $this->assertStringNotContainsString(\App\Lang::t('account.muted'), $me->get('/account')->body);
    }
}
