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

    // ---- Slice 3: tags join the index, prefix on the last word, sort choice ----

    private function storyRow(string $title, string $slug, string $summary): void
    {
        $this->db()->query(
            "INSERT INTO stories (title, slug, author_id, rating_id, validated, summary)
             VALUES (?, ?, (SELECT id FROM users WHERE penname = 'Demo Author'), (SELECT id FROM ratings LIMIT 1), 1, ?)",
            [$title, $slug, $summary]
        );
    }

    private function makeTag(string $name, ?int $canonical = null): int
    {
        $db = $this->db();
        $db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES ((SELECT id FROM tag_types WHERE name = \'genre\'), ?, ?)', [$name, $canonical]);
        return (int) $db->lastInsertId();
    }

    private function attachTag(int $storyId, int $tagId): void
    {
        $this->db()->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$storyId, $tagId]);
    }

    public function test_prefix_on_the_last_word_matches(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame('the-rabbit-hole', $repo->search('rabbi', [], 20, 0, 0)['rows'][0]['slug'] ?? null, 'a two-char last word expands to word starts');
        $this->assertSame('the-rabbit-hole', $repo->search('rabbit ho', [], 20, 0, 0)['rows'][0]['slug'] ?? null, 'only the LAST word carries the prefix');
        $this->assertSame([], $repo->search('r', [], 20, 0, 0)['rows'], 'a one-char word does not prefix-expand');
    }

    public function test_title_hit_outranks_summary_hit(): void
    {
        $this->storyRow('The Cipher Room', 'cipher-room', 'Nothing to see here.');
        $this->storyRow('Quiet Hours', 'quiet-hours', 'A cipher hides in the walls.');
        $rows = (new \App\Repositories\SearchRepository($this->db()))->search('cipher', [], 20, 0, 0)['rows'];
        $this->assertSame('cipher-room', $rows[0]['slug'] ?? null, 'the title hit ranks first');
        $this->assertSame('quiet-hours', $rows[1]['slug'] ?? null, 'the summary hit still surfaces');
    }

    public function test_tag_name_is_searchable_and_ranks_below_title(): void
    {
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $this->storyRow('The Slow Burn Club', 'slow-burn-club', 'Nothing to see here.');
        $tag = $this->makeTag('Slow Burn');
        $this->attachTag((int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $rows = (new \App\Repositories\SearchRepository($this->db()))->search('slow burn', [], 20, 0, 0)['rows'];
        $this->assertContains('embers', array_column($rows, 'slug'), 'the tag name is searchable');
        $this->assertSame('slow-burn-club', $rows[0]['slug'] ?? null, 'a title match outranks a tag match');
    }

    public function test_synonym_name_finds_the_canonical_stories(): void
    {
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $tag = $this->makeTag('Slow Burn');
        $this->attachTag((int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $this->makeTag('sb', $tag);
        $rows = (new \App\Repositories\SearchRepository($this->db()))->search('sb', [], 20, 0, 0)['rows'];
        $this->assertSame(['embers'], array_column($rows, 'slug'), 'the retired synonym name finds the canonical stories');
    }

    public function test_tags_field_requires_every_name_and_is_case_insensitive(): void
    {
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $this->storyRow('Lulls', 'lulls', 'Another quiet night.');
        $burn = $this->makeTag('Slow Burn');
        $angst = $this->makeTag('Angst');
        $embers = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'];
        $lulls = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'lulls'")['id'];
        $this->attachTag($embers, $burn);
        $this->attachTag($embers, $angst);
        $this->attachTag($lulls, $burn);
        $repo = new \App\Repositories\SearchRepository($this->db());
        // AND semantics on the tag-only listing (no words asked)
        $rows = $repo->search('', ['tags' => 'slow burn, angst'], 20, 0, 0)['rows'];
        $this->assertSame(['embers'], array_column($rows, 'slug'), 'every name must match');
        // the same constraint rides the FTS arm when words are asked too
        $this->assertSame(['embers'], array_column($repo->search('night', ['tags' => 'slow burn, angst'], 20, 0, 0)['rows'], 'slug'));
        // case-insensitive against the stored names: every story carrying the
        // tag matches, whatever case the reader typed
        $hits = array_column($repo->search('', ['tags' => 'SLOW BURN'], 20, 0, 0)['rows'], 'slug');
        sort($hits);
        $this->assertSame(['embers', 'lulls'], $hits);
    }

    public function test_xtags_leaves_out_and_old_tag_id_links_still_work(): void
    {
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $this->storyRow('Lulls', 'lulls', 'Another quiet night.');
        $this->storyRow('Both Kinds', 'both-kinds', 'A night of every mood.');
        $burn = $this->makeTag('Slow Burn');
        $angst = $this->makeTag('Angst');
        $embers = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'];
        $lulls = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'lulls'")['id'];
        $both = (int) $this->db()->one("SELECT id FROM stories WHERE slug = 'both-kinds'")['id'];
        $this->attachTag($embers, $burn);
        $this->attachTag($lulls, $angst);
        $this->attachTag($both, $burn);
        $this->attachTag($both, $angst);
        $repo = new \App\Repositories\SearchRepository($this->db());
        // the old single-id link shape: every story carrying the tag
        $hits = array_column($repo->search('night', ['tag' => $burn], 20, 0, 0)['rows'], 'slug');
        sort($hits);
        $this->assertSame(['both-kinds', 'embers'], $hits);
        // several ids at once: include is AND, so only the story carrying both
        $this->assertSame(['both-kinds'], array_column($repo->search('night', ['tag' => [$burn, $angst]], 20, 0, 0)['rows'], 'slug'));
        // leave-out by id and by name (both-kinds carries Angst, so it leaves too)
        $this->assertSame(['embers'], array_column($repo->search('night', ['xtag' => $angst], 20, 0, 0)['rows'], 'slug'));
        $this->assertSame(['embers'], array_column($repo->search('night', ['xtags' => 'angst'], 20, 0, 0)['rows'], 'slug'));
    }

    public function test_updated_sort_orders_by_updated_at(): void
    {
        $this->storyRow('Zephyr Cipher', 'zephyr-cipher', 'Nothing to see here.');
        $this->storyRow('Quiet Hours', 'quiet-hours', 'A zephyr cipher hides in the walls.');
        $this->db()->query("UPDATE stories SET updated_at = '2020-01-01T00:00:00Z' WHERE slug = 'zephyr-cipher'");
        $this->db()->query("UPDATE stories SET updated_at = '2026-06-01T00:00:00Z' WHERE slug = 'quiet-hours'");
        $repo = new \App\Repositories\SearchRepository($this->db());
        $this->assertSame('zephyr-cipher', $repo->search('zephyr cipher', [], 20, 0, 0)['rows'][0]['slug'] ?? null, 'relevance (default): the title hit first');
        $this->assertSame('quiet-hours', $repo->search('zephyr cipher', [], 20, 0, 0, 0, 'updated')['rows'][0]['slug'] ?? null, 'updated sort: the newer story first');
    }

    public function test_like_fallback_matches_tag_names_and_folds_the_curly_apostrophe(): void
    {
        // Rows go in first: the 014 triggers reference the FTS tables, so the
        // drop happens after seeding, exactly as in the FTS-less runtime shape.
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $this->storyRow('Don’t Stop Now', 'dont-stop-now', 'Nothing to see here.');
        $this->storyRow("Won't Back Down", 'wont-back-down', 'Nothing to see here.');
        $tag = $this->makeTag('Slow Burn');
        $this->attachTag((int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $this->db()->query('DROP TABLE stories_fts');
        $this->db()->query('DROP TABLE chapters_fts');
        $repo = new \App\Repositories\SearchRepository($this->db());
        // tag names ride the LIKE arm (embers' summary has no 'slow' in it)
        $this->assertContains('embers', array_column($repo->search('slow', [], 20, 0, 0)['rows'], 'slug'));
        // the fold: a straight apostrophe in the query finds the curly one stored, and back
        $this->assertContains('dont-stop-now', array_column($repo->search("don't", [], 20, 0, 0)['rows'], 'slug'));
        $this->assertContains('wont-back-down', array_column($repo->search('won’t', [], 20, 0, 0)['rows'], 'slug'));
        $this->assertSame('like', $repo->search('slow', [], 20, 0, 0)['mode']);
    }

    public function test_search_with_tags_and_exclusions_is_one_query(): void
    {
        $db = $this->db();
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $tag = $this->makeTag('Slow Burn');
        $this->attachTag((int) $db->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $rows = (new \App\Repositories\SearchRepository($db))->searchWithTaxonomies(
            'night', ['tags' => 'slow burn', 'xtags' => 'angst', 'tag' => [$tag]], 20, 0, 0
        )['rows'];
        $db->onQuery(fn () => null);
        $this->assertSame(['embers'], array_column($rows, 'slug'));
        $this->assertSame(1, $queries, "tags and exclusions rode {$queries} queries, budget is 1");
    }

    public function test_fts_syntax_characters_in_q_are_inert(): void
    {
        $repo = new \App\Repositories\SearchRepository($this->db());
        foreach (['"AND OR NEAR(', '*', 'NEAR("a" "b", 3)', 'a OR b'] as $q) {
            $r = $repo->search($q, [], 20, 0, 0);
            $this->assertContains($r['mode'], ['fts', 'like', 'none', 'filter'], $q);
        }
        $this->assertSame(200, $this->client()->get('/search', ['q' => '*'])->status);
        $this->assertSame(200, $this->client()->get('/search', ['q' => '"AND OR NEAR('])->status);
    }

    // ---- Slice 3, Task 3.4: the form, the controller coercion, the browse link ----

    public function test_search_form_carries_tag_fields_sort_and_the_browse_link(): void
    {
        $c = $this->client();
        $res = $c->get('/search', ['q' => 'slow', 'tags' => 'Slow Burn', 'xtags' => 'gore', 'sort' => 'updated']);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('name="tags"', $res->body);
        $this->assertStringContainsString('value="Slow Burn"', $res->body, 'the tags field is pre-filled');
        $this->assertStringContainsString('name="xtags"', $res->body);
        $this->assertStringContainsString('value="gore"', $res->body, 'the leave-out field is pre-filled');
        $this->assertStringContainsString('name="sort"', $res->body);
        $this->assertStringContainsString('selected', $res->body, 'the chosen sort is selected');
        $this->assertStringContainsString('/browse/filter', $res->body, 'the browse link is on the page');
        // No inline script: every script tag on the page loads an external file
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $res->body, 'no inline script');
        $this->assertSame('noindex', $res->headers['X-Robots-Tag'] ?? '');
        // the fields echo escaped
        $escaped = $c->get('/search', ['tags' => '<b>'])->body;
        $this->assertStringContainsString('&lt;b&gt;', $escaped);
        $this->assertStringNotContainsString('value="<b>"', $escaped);
        // junk sort coerces to relevance; arrays where strings are expected ignore
        $this->assertSame(200, $c->get('/search', ['sort' => 'hax'])->status);
        $this->assertSame(200, $c->get('/search', ['tags' => ['x'], 'xtags' => ['y']])->status);
        $this->assertSame(200, $c->get('/search', ['tag' => ['x', ['nested']]])->status);
    }

    public function test_old_tag_id_links_keep_working_through_the_form(): void
    {
        $tag = (int) $this->db()->one('SELECT id FROM tags LIMIT 1')['id'];
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $this->attachTag((int) $this->db()->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $c = $this->client();
        $this->assertSame(200, $c->get('/search', ['tag' => (string) $tag])->status);
        $this->assertStringContainsString('href="/story/view/embers"', $c->get('/search', ['tag' => (string) $tag])->body);
        // several ids, the slice-3 shape, including a junk id that coerces away
        $this->assertSame(200, $c->get('/search', ['tag' => [(string) $tag, 'abc', '0']])->status);
        $this->assertStringContainsString('href="/story/view/embers"', $c->get('/search', ['tag' => [(string) $tag, 'abc', '0']])->body);
        // the browse link carries the ids
        $this->assertStringContainsString('/browse/filter', $c->get('/search', ['tag' => (string) $tag])->body);
    }

    public function test_the_tag_name_constraint_never_scans_story_tags(): void
    {
        $db = $this->db();
        $this->storyRow('Embers', 'embers', 'A quiet night in the north.');
        $tag = $this->makeTag('Slow Burn');
        $this->attachTag((int) $db->one("SELECT id FROM stories WHERE slug = 'embers'")['id'], $tag);
        $sqls = [];
        $db->onQuery(function (string $sql) use (&$sqls): void { $sqls[] = $sql; });
        (new \App\Repositories\SearchRepository($db))->searchWithTaxonomies('night', ['tags' => 'slow burn'], 20, 0, 0);
        $db->onQuery(fn () => null);
        $stmt = array_values(array_filter($sqls, fn (string $s): bool => str_contains($s, 'COALESCE(tf.canonical_id')));
        $this->assertCount(1, $stmt, 'the tag-name constraint rode the one ranking statement');
        $details = array_column($db->query('EXPLAIN QUERY PLAN ' . $stmt[0])->fetchAll(), 'detail');
        $scan = array_values(array_filter($details, fn (string $d): bool => str_contains($d, 'SCAN x') || str_contains($d, 'SCAN story_tags')));
        $this->assertSame([], $scan, 'story_tags must be sought, never scanned: ' . implode(' | ', $details));
        $this->assertTrue((bool) array_filter($details, fn (string $d): bool => str_contains($d, 'SEARCH x USING')), 'the EXISTS seeks story_tags');
        $this->assertTrue((bool) array_filter($details, fn (string $d): bool => str_contains($d, 'SEARCH tf USING')), 'the name probe seeks the tags row');
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
