<?php // app/Features/Search/Tests/SearchTest.php
namespace App\Features\Search\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-search-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Fresh handle on the shared temp file (the SeriesRepositoryTest shape
     *  via a method, so every call sees committed fixture state). */
    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-search-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-search-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    /** Kept for Task 3's controller cases (the SeriesTest pattern: the factory
     *  keeps the App as $this->app for direct handle() drives). */
    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_fts_search_finds_title_summary_and_chapter_content(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $rows = $repo->search('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null, 'title hit');
        // chapter-only term (finding 8, probed: 'shelves' exists only in seeded chapter content)
        $rows = $repo->search('shelves', [], 20, 0, 0)['rows'];
        $this->assertContains('the-rabbit-hole', array_column($rows, 'slug'), 'chapter content hit surfaces the story');
    }

    public function test_search_hides_pending_chapter_content_until_validated(): void
    {
        // Reader surfaces gate ch.validated = 1 unconditionally (the TOC blob,
        // /story/read); search must not make pending chapter text or titles
        // discoverable ahead of moderation on any path or for any viewer.
        $sid = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db()->query('INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?,?,?,?,0,4)',
            [$sid, 9, 'Xyqar Draft', 'Zylophant confidential draft text here.']);
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame([], $repo->search('zylophant', [], 20, 0, 0)['rows'], 'pending chapter body hidden from guests');
        $this->assertSame([], $repo->search('zylophant', [], 20, 0, 1)['rows'], 'pending chapter body hidden from members too (reader gates are unconditional)');
        $this->assertSame([], $repo->search('xyqar', [], 20, 0, 0)['rows'], 'pending chapter TITLE hidden');
        $this->assertSame([], $repo->searchLike('zylophant', [], 20, 0, 0)['rows'], 'the LIKE fallback gates the same');
        // approving the chapter flips the same term findable through the live index
        $this->db()->query('UPDATE chapters SET validated = 1 WHERE story_id = ? AND position = 9', [$sid]);
        $this->assertSame('the-rabbit-hole', $repo->search('zylophant', [], 20, 0, 0)['rows'][0]['slug'] ?? null, 'validated chapter content searchable');
    }

    public function test_search_gates_and_personalizes_restricted(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $guest = $repo->search('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame([], $guest, 'restricted hidden from guests');
        $member = $repo->search('rabbit', [], 20, 0, 1)['rows'];
        $this->assertSame('the-rabbit-hole', $member[0]['slug'] ?? null, 'members see restricted');
        $this->db()->query("UPDATE stories SET is_restricted = 0, validated = 0 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame([], $repo->search('rabbit', [], 20, 0, 1)['rows'], 'unvalidated never searched');
    }

    /** Through the controller: a session revoked by a password change
     *  searches as a guest (the Viewer::id doctrine, f7ca7c3's
     *  /browse/recent rider), so restricted rows stay hidden after
     *  revocation even though the session cookie still names the member. */
    public function test_revoked_session_searches_as_a_guest(): void
    {
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $member = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $c = $this->client($member);
        $this->assertStringContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'rabbit'])->body);
        $this->db()->query("UPDATE users SET password_hash = 'rotated' WHERE id = $member");
        $this->assertStringNotContainsString('the-rabbit-hole', $c->get('/search', ['q' => 'rabbit'])->body);
    }

    public function test_search_filters_category_rating_completed_language(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame([], $repo->search('rabbit', ['category' => 'nope'], 20, 0, 0)['rows']);
        $rows = $repo->search('rabbit', ['category' => 'general'], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null);
        $teen = (int) $this->db()->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['rating_id' => $teen], 20, 0, 0)['rows'][0]['slug'] ?? null);
        $this->assertSame([], $repo->search('rabbit', ['rating_id' => $teen + 999], 20, 0, 0)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['completed' => false], 20, 0, 0)['rows'][0]['slug'] ?? null, 'WIP story');
        $this->assertSame([], $repo->search('rabbit', ['completed' => true], 20, 0, 0)['rows']);
        $this->db()->query("UPDATE stories SET language = 'pt-BR' WHERE slug = 'the-rabbit-hole'");
        $this->assertSame([], $repo->search('rabbit', ['language' => 'en'], 20, 0, 0)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit', ['language' => 'pt-BR'], 20, 0, 0)['rows'][0]['slug'] ?? null);
    }

    public function test_search_junk_query_and_overlong_input(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame(['rows' => [], 'hasMore' => false, 'mode' => 'none'], $repo->search('   ', [], 20, 0, 0));
        $this->assertSame(['rows' => [], 'hasMore' => false, 'mode' => 'none'], $repo->search('"', [], 20, 0, 0), 'quote-only input sanitizes to nothing');
        $many = $repo->search('one two three four five six seven eight nine ten', [], 20, 0, 0);
        $this->assertContains($many['mode'], ['fts', 'like']);
    }

    public function test_like_fallback_path_searches_and_escapes(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $rows = $repo->searchLike('rabbit', [], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null);
        // a literal % must not match everything
        $this->assertSame([], $repo->searchLike('%%%', [], 20, 0, 0)['rows']);
        $this->assertSame([], $repo->searchLike('rabbit', ['language' => 'zz'], 20, 0, 0)['rows']);
    }

    public function test_searchfts_seam_honors_filters_and_gates(): void
    {
        // The Task 2 FTS seam: every filter binds in the corrected order
        // ([$match, $match, $me, ...filters...]) and the guest gates apply.
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->db()->query("UPDATE stories SET language = 'en' WHERE slug = 'the-rabbit-hole'");
        $teen = (int) $this->db()->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $rows = $repo->searchFts('rabbit', ['category' => 'general', 'rating_id' => $teen, 'completed' => false, 'language' => 'en'], 20, 0, 0)['rows'];
        $this->assertSame('the-rabbit-hole', $rows[0]['slug'] ?? null, 'all four filters set at once still finds the story');
        $this->assertSame([], $repo->searchFts('rabbit', ['language' => 'zz'], 20, 0, 0)['rows'], 'filter alone can empty the seam');
        $this->assertSame([], $repo->searchFts('shelves', ['completed' => true], 20, 0, 0)['rows'], 'chapter-content hit obeys filters');
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame([], $repo->searchFts('rabbit', [], 20, 0, 0)['rows'], 'restricted hidden from guests');
        $this->assertSame('the-rabbit-hole', $repo->searchFts('rabbit', [], 20, 0, 1)['rows'][0]['slug'] ?? null, 'members see restricted');
    }

    public function test_dispatcher_falls_back_to_like_when_fts_tables_are_missing(): void
    {
        // An FTS5-less runtime shape: the virtual tables do not exist. The page
        // path never probes (finding 6); the failed FTS prepare IS the probe,
        // memoized negative, and the LIKE fold takes over in the same call.
        $this->db()->query('DROP TABLE stories_fts');
        $this->db()->query('DROP TABLE chapters_fts');
        $this->db()->query("UPDATE stories SET language = 'en' WHERE slug = 'the-rabbit-hole'");
        $repo = new \App\Repositories\SearchRepository($this->db());
        $r = $repo->search('rabbit', [], 20, 0, 0);
        $this->assertSame('like', $r['mode']);
        $this->assertSame('the-rabbit-hole', $r['rows'][0]['slug'] ?? null);
        $fold = $repo->searchWithTaxonomies('rabbit', ['language' => 'en'], 20, 0, 0);
        $this->assertSame('like', $fold['mode'], 'the page fold fell back too');
        $this->assertSame('the-rabbit-hole', $fold['rows'][0]['slug'] ?? null);
        $this->assertNotSame([], $fold['ratings'], 'taxonomies still ride the fallback fold');
        $this->assertNotSame([], $fold['categories']);
        $this->assertFalse((new \App\Repositories\SearchRepository($this->db()))->ftsAvailable(), 'the probe reports the negative');
    }

    public function test_search_page_renders_form_results_and_noindex(): void
    {
        $res = $this->client()->get('/search', ['q' => 'rabbit']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('name="q"', $res->body); // the form persists
        $this->assertStringContainsString('noindex', $res->body); // meta
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '');
    }

    /** Task 2: both arms + the page fold drop the muted author's stories for
     *  the viewer alone. The viewer bind sits immediately after the existing
     *  restricted-gate $me bind in TEXT order, so the misbind trap is pinned
     *  too: every filter set at once WITH a viewer still returns the story
     *  (a displaced bind silently empties the results instead). */
    public function test_search_arms_and_fold_filter_muted_authors_for_the_viewer(): void
    {
        $this->db()->query("UPDATE stories SET language = 'en' WHERE slug = 'the-rabbit-hole'");
        $viewer = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $author = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $this->db()->query('INSERT INTO muted (user_id, author_id) VALUES (?, ?)', [$viewer, $author]);
        $repo = new \App\Repositories\SearchRepository($this->db());
        // FTS seam: filtered for the viewer, untouched without one
        $this->assertSame([], $repo->searchFts('rabbit', [], 20, 0, 0, $viewer)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->searchFts('rabbit', [], 20, 0, 0)['rows'][0]['slug'] ?? null);
        // LIKE seam: the same matrix
        $this->assertSame([], $repo->searchLike('rabbit', [], 20, 0, 0, $viewer)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->searchLike('rabbit', [], 20, 0, 0)['rows'][0]['slug'] ?? null);
        // the page fold (the live /search surface) on both arms
        $this->assertSame([], $repo->searchWithTaxonomies('rabbit', [], 20, 0, 0, $viewer)['rows']);
        $this->assertSame('the-rabbit-hole', $repo->searchWithTaxonomies('rabbit', [], 20, 0, 0)['rows'][0]['slug'] ?? null);
        // the misbind trap, no mute row planted: every filter set at once plus
        // the viewer bind in the array still finds the story on every seam
        $this->db()->query('DELETE FROM muted');
        $teen = (int) $this->db()->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $all = ['category' => 'general', 'rating_id' => $teen, 'completed' => false, 'language' => 'en'];
        $this->assertSame('the-rabbit-hole', $repo->searchFts('rabbit', $all, 20, 0, 0, $viewer)['rows'][0]['slug'] ?? null, 'fts filters survive the viewer bind');
        $this->assertSame('the-rabbit-hole', $repo->searchLike('rabbit', $all, 20, 0, 0, $viewer)['rows'][0]['slug'] ?? null, 'like filters survive the viewer bind');
        $this->assertSame('the-rabbit-hole', $repo->searchWithTaxonomies('rabbit', $all, 20, 0, 0, $viewer)['rows'][0]['slug'] ?? null, 'the fold too');
    }

    public function test_search_page_empty_and_junk_states(): void
    {
        $bare = $this->client()->get('/search');
        $this->assertSame(200, $bare->status);
        $this->assertStringContainsString('Search', $bare->body);
        // nothing asked yet: the hint, never an empty-result verdict
        $this->assertStringContainsString('Quotes match an exact phrase.', $bare->body);
        $this->assertStringNotContainsString('No stories matched', $bare->body);
        $this->assertStringNotContainsString('rel="next"', $bare->body);
        $junk = $this->client()->get('/search', ['q' => '   ', 'category' => 'nope', 'rating' => 'abc', 'completed' => 'maybe', 'language' => '!!!', 'sort' => 'sideways', 'page' => '0']);
        $this->assertSame(200, $junk->status); // coerced, not a 500
        $this->assertStringContainsString('no stories matched', strtolower($junk->body)); // finding 16: the real empty-state copy
    }

    public function test_search_filters_via_query_string(): void
    {
        $body = $this->client()->get('/search', ['q' => 'rabbit', 'completed' => '1'])->body;
        $this->assertStringNotContainsString('href="/story/view/the-rabbit-hole"', $body, 'WIP story filtered out');
    }

    public function test_search_page_is_one_query(): void
    {
        $app = $this->app;
        $db = $app->container->make(\Kip\Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = $app->handle(new \Kip\Http\Request('GET', '/search', ['q' => 'rabbit'], [], []));
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "search ran {$queries} content queries, budget is 1");
    }

    /** Perf regression guard (qa-full 2026-10-07, probed on a 1,500-story /
     *  6,000-chapter scratch archive): the FTS ranking statement MUST keep the
     *  per-story aggregate in the fts_matches CTE and pin it as the OUTER loop
     *  with CROSS JOIN. The pre-fix shape (JOIN stories ... GROUP BY s.id, ...
     *  ORDER BY rank LIMIT) let the planner flip stories to the outer loop and
     *  re-run the FTS co-routine once per grouped row: 10.0-13.1s median for a
     *  token matching every chapter, 0.78s even for a rare token. The fixed
     *  statement runs the same archive at 14ms median. EQP loop order is
     *  deterministic here because CROSS JOIN is a hard ordering constraint. */
    public function test_fts_statement_pins_the_aggregate_as_the_outer_loop(): void
    {
        $db = $this->db();
        $repo = new \App\Repositories\SearchRepository($db);
        $sqls = [];
        $db->onQuery(function (string $sql) use (&$sqls): void { $sqls[] = $sql; });
        $repo->searchWithTaxonomies('rabbit', [], 20, 0, 1, 1);
        $db->onQuery(fn () => null);
        $fts = array_values(array_filter($sqls, fn (string $s): bool => str_contains($s, 'bm25(')));
        $this->assertCount(1, $fts, 'exactly one FTS ranking statement per search');
        $this->assertStringContainsString('WITH fts_matches', $fts[0], 'the per-story aggregate rides a CTE');
        $this->assertStringContainsString('CROSS JOIN stories s', $fts[0], 'the CTE is pinned as the outer loop');
        $st = $db->query('EXPLAIN QUERY PLAN ' . $fts[0]);
        $details = array_column($st->fetchAll(), 'detail');
        $mScan = array_search('SCAN m', $details, true);
        $sSeek = (int) array_search('SEARCH s USING INTEGER PRIMARY KEY (rowid=?)', $details, true);
        $this->assertNotFalse($mScan, 'the aggregate co-routine is scanned once');
        $this->assertGreaterThan($mScan, $sSeek, 'stories must be sought per aggregate row, never the reverse');
        // and the page still answers through the guarded statement
        $this->assertSame('the-rabbit-hole', $repo->searchWithTaxonomies('rabbit', [], 20, 0, 0)['rows'][0]['slug'] ?? null);
    }
}
