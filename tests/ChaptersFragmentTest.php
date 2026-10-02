<?php // tests/ChaptersFragmentTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// The reader fragment endpoint (comp M3, reading-experience plan Task 5):
// GET /story/fragment/{slug}/{n} renders ONE .chapter-unit - the h-entry
// article plus the chapter-end block - for the infinite module to append to
// the read page. The flat router maps the URL's second segment to the raw
// method name, so the action is fragment(), not chaptersFragment(). Gate
// parity with read() is the law: validated + not-deleted + the SQL
// restricted gate + the chapter-exists check, but the adult gate answers
// 404 - a fragment is never a page, so it must never render the gate's 200
// shape or leak one line of prose. Members' fragment GETs record no reading
// progress, every response rides X-Robots-Tag: noindex, the static cache
// never whitelists the fragment prefix, and the fragment render stays
// inside the one-query budget on the member path too.
final class ChaptersFragmentTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-frag-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -strlen('.sqlite'))); // the zero-byte tempnam stub itself
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-frag-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    public function test_fragment_renders_one_chapter_unit_and_nothing_else(): void
    {
        $res = $this->client()->get('/story/fragment/the-rabbit-hole/2');
        $this->assertSame(200, $res->status);
        $body = $res->body;
        // ONE container: the unit with its read URL (history) and the next
        // fragment URL (the fetch chain) - a READ url for history, a FRAGMENT
        // url for the next fetch
        $this->assertSame(1, substr_count($body, '<div class="chapter-unit"'), 'exactly one unit per fragment');
        $this->assertStringContainsString(
            '<div class="chapter-unit" data-read-url="/story/read/the-rabbit-hole/2" data-next-url="/story/fragment/the-rabbit-hole/3">',
            $body
        );
        // the chapter's prose AND its chapter-end block
        $this->assertStringContainsString('Through the little door, the garden is wrong.', $body);
        $this->assertStringContainsString('<h1 class="p-name">Through</h1>', $body);
        $this->assertStringContainsString('action="/kudos/add/the-rabbit-hole"', $body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole#reviews">Review · 0</a>', $body);
        // the article stamps its own story-span (the position module reads
        // per-article spans) and the read beacon rides along
        $this->assertStringContainsString('<article class="h-entry" data-p-start="17" data-p-end="50" data-next-url="/story/fragment/the-rabbit-hole/3">', $body);
        $this->assertStringContainsString('<img src="/beacon/read/1/2"', $body);
        // fragment only: never a document, never the reader chrome
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('reader-bar', $body);
        $this->assertStringNotContainsString('site-head', $body);
        $this->assertStringNotContainsString('reader-head', $body);
    }

    public function test_guest_fragment_bytes_are_identical_across_calls(): void
    {
        $client = $this->client();
        $first = $client->get('/story/fragment/the-rabbit-hole/1')->body;
        $second = $client->get('/story/fragment/the-rabbit-hole/1')->body;
        $this->assertSame($first, $second, 'two guest fetches of one fragment never differ');
    }

    public function test_adult_story_fragment_gates_to_404_without_consent(): void
    {
        // Gate parity, the fragment shape: never the 200 gate page (its
        // heading must not render), never one line of prose - the seed
        // story's chapter body is "Body."
        $res = $this->client()->get('/story/fragment/after-hours/1');
        $this->assertSame(404, $res->status);
        $this->assertStringNotContainsString('Body.', $res->body, 'not a line of prose behind the gate');
        $this->assertStringNotContainsString('Adult content ahead.', $res->body, 'never the gate page shape');
        $acked = $this->client();
        $acked->cookie('age_ok', '1');
        $res = $acked->get('/story/fragment/after-hours/1');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Body.', $res->body);
    }

    public function test_restricted_story_fragment_gates_like_read(): void
    {
        $this->db->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client()->get('/story/fragment/the-rabbit-hole/2')->status, 'guests get the SQL 404');
        $res = $this->client($this->memberId())->get('/story/fragment/the-rabbit-hole/2');
        $this->assertSame(200, $res->status, 'members read what they can read');
        $this->assertStringContainsString('Through the little door, the garden is wrong.', $res->body);
    }

    public function test_unvalidated_chapter_or_story_fragment_404(): void
    {
        $this->db->query(
            "UPDATE chapters SET validated = 0 WHERE story_id = (SELECT id FROM stories WHERE slug = 'the-rabbit-hole') AND position = 2");
        $this->assertSame(404, $this->client()->get('/story/fragment/the-rabbit-hole/2')->status, 'unvalidated chapter');
        $this->db->query("UPDATE stories SET validated = 0 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client()->get('/story/fragment/the-rabbit-hole/1')->status, 'unvalidated story');
    }

    public function test_deleted_story_fragment_404(): void
    {
        $this->db->query("UPDATE stories SET deleted_at = '2026-10-01T00:00:00Z' WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client()->get('/story/fragment/the-rabbit-hole/1')->status);
    }

    public function test_malformed_chapter_numbers_404(): void
    {
        // read() clamps the bare-URL spell; the fragment has no page to
        // clamp into: junk, zero, negative, and past-the-end all 404
        foreach (['abc', '0', '-1', '99'] as $n) {
            $this->assertSame(404, $this->client()->get('/story/fragment/the-rabbit-hole/' . rawurlencode($n))->status, "n={$n}");
        }
    }

    public function test_last_chapter_fragment_stops_without_data_next_url(): void
    {
        $res = $this->client()->get('/story/fragment/the-rabbit-hole/3');
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('data-next-url', $res->body, 'the JS stop signal: no next fragment URL anywhere');
        $this->assertStringContainsString('<div class="chapter-unit" data-read-url="/story/read/the-rabbit-hole/3">', $res->body,
            'the unit still carries its read URL');
    }

    public function test_every_fragment_response_rides_the_noindex_header(): void
    {
        $this->assertSame('noindex', $this->client()->get('/story/fragment/the-rabbit-hole/2')->headers['X-Robots-Tag'] ?? '',
            'the happy path never enters an index');
        $miss = $this->client()->get('/story/fragment/the-rabbit-hole/99');
        $this->assertSame(404, $miss->status);
        $this->assertSame('noindex', $miss->headers['X-Robots-Tag'] ?? '', '404s ride the header too');
    }

    public function test_member_fragment_records_no_reading_progress(): void
    {
        $id = $this->memberId();
        $this->db->query(
            'INSERT INTO reading_history (user_id, story_id, last_position) VALUES (?, (SELECT id FROM stories WHERE slug = ?), 1)',
            [$id, 'the-rabbit-hole']);
        $res = $this->client($id)->get('/story/fragment/the-rabbit-hole/2');
        $this->assertSame(200, $res->status);
        $row = $this->db->one('SELECT last_position FROM reading_history WHERE user_id = ?', [$id]);
        $this->assertSame(1, (int) $row['last_position'], 'an append is not a chapter open: the fragment never writes progress');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM reading_history')['c'], 'and never plants a row');
    }

    public function test_member_fragment_carries_the_kudos_token(): void
    {
        $body = $this->client($this->memberId())->get('/story/fragment/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('name="_token"', $body, 'the member kudos form carries the session token');
        $guest = $this->client()->get('/story/fragment/the-rabbit-hole/2')->body;
        $this->assertStringNotContainsString('name="_token"', $guest, 'guests render the tokenless form');
    }

    /** The enhancement layer's contract, infinite module: the module file
     *  invents no URL (every fetch target and history entry rides the
     *  server-rendered data attributes), runs one observer per page, keeps
     *  one fetch in flight, parses with <template>, and disconnects at the
     *  end of the story. */
    /** The unit-state template: the reader chrome the infinite module swaps
     *  in when this chapter becomes the one on screen. Everyone gets the
     *  keys targets, the chapter count and both bookmark slots (login links
     *  for guests); members get the forms (saved state included) and the
     *  progress URL. */
    public function test_unit_state_template_carries_the_chapter_chrome(): void
    {
        $guest = $this->client()->get('/story/fragment/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<template class="unit-state" data-position="2" data-prev="/story/read/the-rabbit-hole/1" data-next="/story/read/the-rabbit-hole/3"'
            . ' data-focus-url="/story/read/the-rabbit-hole/2?focus=1" data-exit-focus="/story/read/the-rabbit-hole/2" data-text-url="/story/read/the-rabbit-hole/2#text">', $guest);
        // guests get the login link in both slots: a member whose session
        // expired mid-scroll has the forms swapped out, never left stale
        $this->assertStringContainsString('<div data-slot="rt"><a class="rt-ctl" href="/auth/login">', $guest);
        $this->assertStringContainsString('<div data-slot="bar">', $guest);
        $this->assertStringContainsString('<span class="bar-count">2 / 3</span>', $guest);
        $this->assertStringNotContainsString('/reader/bookmark', $guest, 'guests get no bookmark forms');
        $this->assertStringNotContainsString('data-progress-url', $guest);

        $member = $this->client($this->memberId());
        $member->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', []);
        $body = $member->get('/story/fragment/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('data-progress-url="/reader/progress/the-rabbit-hole/2"', $body);
        $this->assertSame(2, substr_count($body, 'action="/reader/bookmarkremove/the-rabbit-hole/2"'), 'both controls, saved');
        $other = $member->get('/story/fragment/the-rabbit-hole/3')->body;
        $this->assertSame(2, substr_count($other, 'action="/reader/bookmarkadd/the-rabbit-hole/3"'), 'an unsaved chapter offers Bookmark');
        $this->assertStringContainsString('data-next=""', $other, 'the last chapter has no next target');
    }

    /** A member fragment carries their session token, so no shared cache may
     *  store it. */
    public function test_fragment_is_never_shared_cacheable(): void
    {
        $res = $this->client()->get('/story/fragment/the-rabbit-hole/2');
        $this->assertSame('private, no-store', $res->headers['Cache-Control'] ?? null);
    }

    public function test_the_infinite_module_contract(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/infinite.js');
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/infinite.js');
        $this->assertStringContainsString('data-js-module="infinite"', $js, 'the loader keys off this marker');
        $this->assertStringContainsString("rootMargin: '600px'", $js, 'the append band starts well below the fold');
        $this->assertStringContainsString('IntersectionObserver', $js, 'one observer per page');
        $this->assertStringContainsString("querySelector('.chapter-unit')", $js, 'the whole unit is the insert shape');
        $this->assertStringContainsString('data-next-url', $js, 'the fetch chain updates from the inserted unit');
        $this->assertStringContainsString('data-read-url', $js, 'history rides the unit read URL');
        $this->assertStringContainsString('history.replaceState', $js);
        $this->assertStringContainsString('busy', $js, 'one fetch in flight at a time');
        $this->assertStringContainsString('.catch(function () { busy = false; retry(); })', $js, 'a failed fetch never wedges the module');
        $this->assertStringContainsString('MAX_RETRIES', $js, 'and retries are bounded');
        // the chrome follows the chapter on screen, never the prefetch
        $this->assertStringContainsString("template.unit-state", $js);
        $this->assertStringContainsString("rootMargin: '0px 0px -50% 0px'", $js, 'active = top crossed the middle');
        $this->assertStringContainsString("data-progress-url", $js);
        $this->assertStringContainsString("'data-focus-url', 'data-exit-focus', 'data-text-url'", $js, 'every keys target follows the chapter');
        $this->assertStringContainsString("readUrl + window.location.search", $js, 'focus mode survives the history swap');
        $this->assertStringContainsString('input[name="return_to"]', $js, 'Text settings return to the chapter on screen');
        $this->assertStringContainsString("classList.add('is-stacked')", $js, 'the static prev/next block yields once chapters stack');
        $this->assertStringContainsString('.focus-hint a, .reader-dock a[href^="/story/read/"]', $js, 'visible focus exits follow the chapter');
        $this->assertStringContainsString('if (res.ok && n > acked) { acked = n; }', $js, 'progress counts only what the server took');
        $this->assertStringContainsString("searchParams.set('fragment', '1')", $js, 'the listing branch fetches the card loop');
        $this->assertStringContainsString('disconnect', $js, 'the observer stops at the last chapter');
        $loader = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("'infinite'", $loader, 'the loader knows the module');
    }

    public function test_reader_css_carries_the_unit_and_sentinel_styles(): void
    {
        $css = str_replace(' ', '', (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css'));
        $this->assertStringContainsString('.chapter-unit+.chapter-unit{', $css, 'the divider between units');
        $this->assertStringContainsString('.reader-sentinel{', $css, 'the module\'s observe target has a box');
    }
}
