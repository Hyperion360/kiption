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
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
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
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
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

    /** Task 2's muter-only matrix: every listing surface in the ruling set
     *  drops the muted author's stories for the muter alone; guests and the
     *  ruling set's untouchable surfaces (direct URLs, profiles) see
     *  everything. Planted fixtures: the seeded open challenge gains one
     *  confirmed item so the fold's item branch has a row to filter. */
    public function test_muted_authors_vanish_from_listings_for_the_muter_only(): void
    {
        $this->db()->query("UPDATE stories SET language = 'en'");
        $this->client($this->memberId())->postWithToken('/mute/add/demo-author');
        // recent: the muter loses both seeded stories; a guest sees everything
        $recent = $this->client($this->memberId())->get('/browse/recent')->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $recent);
        $this->assertStringNotContainsString('/story/view/after-hours', $recent);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/browse/recent')->body);
        // category + language the same shape
        $this->assertStringNotContainsString('the-rabbit-hole', $this->client($this->memberId())->get('/browse/category/general')->body);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $this->client($this->memberId())->get('/browse', ['language' => 'en'])->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/browse', ['language' => 'en'])->body);
        // search (the FTS arm; the LIKE arm has its own test below)
        $s = $this->client($this->memberId())->get('/search', ['q' => 'rabbit'])->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $s);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/search', ['q' => 'rabbit'])->body);
        // the challenges fold's item branch (planted confirmed item)
        $this->db()->query("INSERT INTO challenge_items (challenge_id, story_id, position, confirmed)
            VALUES ((SELECT id FROM challenges WHERE slug = 'community-challenge'),
                    (SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 1, 1)");
        $ch = $this->client($this->memberId())->get('/challenges/view/community-challenge')->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $ch);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/challenges/view/community-challenge')->body);
        // the series fold's item branch (the seeded confirmed item)
        $ser = $this->client($this->memberId())->get('/series/view/down-the-rabbit-hole')->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $ser);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/series/view/down-the-rabbit-hole')->body);
        // direct URLs always render for the muter (the ruling: mute is a
        // curation filter, not a block); so does the muted author's profile
        $this->assertSame(200, $this->client($this->memberId())->get('/story/view/the-rabbit-hole')->status);
        $this->assertSame(200, $this->client($this->memberId())->get('/user/view/demo-author')->status);
    }

    public function test_muted_authors_vanish_from_the_like_search_arm(): void
    {
        // FTS5-less runtime shape (the SearchTest fallback idiom): the page
        // fold falls back to the LIKE arm, which must filter identically.
        $this->db()->query('DROP TABLE stories_fts');
        $this->db()->query('DROP TABLE chapters_fts');
        $this->client($this->memberId())->postWithToken('/mute/add/demo-author');
        $s = $this->client($this->memberId())->get('/search', ['q' => 'rabbit'])->body;
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $s);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client()->get('/search', ['q' => 'rabbit'])->body);
    }

    public function test_anonymous_renders_stay_byte_identical(): void
    {
        $before = $this->client()->get('/browse/recent')->body;
        $this->client($this->memberId())->postWithToken('/mute/add/demo-author');
        $this->assertSame($before, $this->client()->get('/browse/recent')->body);
    }

    /** Finding 9: the flag-off semantics disable the FILTERING everywhere,
     *  not just the buttons. A planted mute row stops filtering the moment
     *  the flag drops. */
    public function test_mute_flag_off_stops_filtering_the_listings_too(): void
    {
        $this->client($this->memberId())->postWithToken('/mute/add/demo-author');
        $db = $this->db();
        $db->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['mute']);
        \App\Features::init($db, []);
        $body = $this->client($this->memberId())->get('/browse/recent')->body;
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $body, 'the planted mute row stops filtering when the flag is off');
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $this->client($this->memberId())->get('/search', ['q' => 'rabbit'])->body, 'search too');
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
