<?php // app/Features/Browse/Tests/BrowseTest.php
namespace App\Features\Browse\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BrowseTest extends TestCase
{
    private string $dsn = '';
    private App $app;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kiption-browse-') . '.sqlite';
        $this->dsn = 'sqlite:' . $path;
        $db = new Database($this->dsn);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $db->query('INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)', ['a@x.test', 'h', 'Demo Author']);
        $db->query('INSERT INTO ratings (label, position) VALUES (?, ?)', ['Teen', 1]);
        $db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', ['General', 'general']);
        $db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated, updated_at) VALUES (?, ?, 1, 1, 1, ?)',
            ['The Rabbit Hole', 'the-rabbit-hole', '2026-09-01T10:00:00Z']);
        $db->query('INSERT INTO story_categories (story_id, category_id) VALUES (1, 1)');
        $this->app = new App($this->config());
    }

    protected function tearDown(): void
    {
        unset($this->app);
        @unlink(substr($this->dsn, 7));
        @unlink(substr($this->dsn, 7) . '-wal');
        @unlink(substr($this->dsn, 7) . '-shm');
    }

    public function test_browse_lists_categories_with_counts(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('General', $res->body);
        $this->assertStringContainsString('1 stor', $res->body);
    }

    public function test_recent_lists_validated_stories(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
        $this->assertStringContainsString('/story/view/the-rabbit-hole', $res->body);
    }

    public function test_recent_page_param_coerced_to_page_one(): void
    {
        $default = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        foreach (['banana', '-3', '0'] as $junk) {
            $res = $this->app->handle(new Request('GET', '/browse/recent', ['page' => $junk], [], []));
            $this->assertSame(200, $res->status);
            $this->assertSame($default->body, $res->body, "page={$junk} must coerce to page 1");
        }
    }

    public function test_category_lists_its_stories(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/category/general', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        // a short listing has no older page, so no Older link
        $this->assertStringNotContainsString('/browse/category/general?page=2', $res->body);
        $full = $this->pagedApp()->handle(new Request('GET', '/browse/category/general', [], [], []));
        $this->assertStringContainsString('/browse/category/general?page=2', $full->body, 'a full page links its older page');
    }

    public function test_unknown_category_renders_honest_empty_state(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/category/nope', [], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('No stories yet', $res->body);
    }

    public function test_huge_page_param_returns_empty_page_not_500(): void
    {
        foreach (['/browse/recent', '/browse/category/general'] as $path) {
            $res = $this->app->handle(new Request('GET', $path, ['page' => '99999999999999999999'], [], []));
            $this->assertSame(200, $res->status, "{$path} must not 500 on a huge page param");
            $this->assertStringContainsString('No stories yet', $res->body);
        }
    }

    public function test_h1_names_the_listing_on_each_route(): void
    {
        $recent = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        $this->assertStringContainsString('<h1>Recently updated</h1>', $recent->body);
        $category = $this->app->handle(new Request('GET', '/browse/category/general', [], [], []));
        $this->assertStringContainsString('<h1>Category: general</h1>', $category->body);
    }

    /** One story per page: a single row fills a page, so Older links
     *  exist exactly where an older page does. */
    private function pagedApp(): App
    {
        return new App(['items_per_page' => 1] + $this->config());
    }

    private function config(): array
    {
        return [
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => $this->dsn],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'items_per_page' => 20,
        ];
    }

    /** A fresh client over the same sqlite file; actingAs bakes a member
     *  session for the Continue-pill shapes. */
    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App($this->config()));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** The filter discriminator: the fixture's Rabbit Hole is the WIP,
     *  under-10k side of the split; The Long Finish is the completed,
     *  over-10k side. One extra story discriminates all three facets. */
    private function seedFilterFixture(): void
    {
        $db = new Database($this->dsn);
        $db->query("UPDATE stories SET word_count = 5000 WHERE slug = 'the-rabbit-hole'");
        $db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, updated_at) VALUES (?, ?, ?, 1, 1, 1, 1, 20000, ?)",
            ['The Long Finish', 'the-long-finish', 'Done at last.', '2026-09-05T10:00:00Z']);
    }

    /** @return array<string, list<string>> [filter, shown title, hidden title] */
    public static function filters(): array
    {
        return [
            'complete keeps only completed works' => ['complete', 'The Long Finish', 'The Rabbit Hole'],
            'wip keeps only unfinished works' => ['wip', 'The Rabbit Hole', 'The Long Finish'],
            'under10k keeps only short works' => ['under10k', 'The Rabbit Hole', 'The Long Finish'],
        ];
    }

    #[DataProvider('filters')]
    public function test_each_filter_actually_filters_the_listing(string $filter, string $shown, string $hidden): void
    {
        $this->seedFilterFixture();
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => $filter], [], []));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString($shown, $res->body);
        $this->assertStringNotContainsString($hidden, $res->body, "filter={$filter} must drop the non-matching story");
    }

    public function test_under10k_boundary_is_exclusive_at_exactly_10000(): void
    {
        // Testing specialist: the SQL is word_count < 10000; a story at
        // exactly 10k words is NOT under 10k. An off-by-one drift to <= would
        // change the documented semantics without failing any other pin.
        $this->seedFilterFixture();
        (new Database($this->dsn))->query("UPDATE stories SET word_count = 10000 WHERE slug = 'the-long-finish'");
        $body = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'under10k'], [], []))->body;
        $this->assertStringNotContainsString('The Long Finish', $body, 'exactly 10k is not under 10k');
        $this->assertStringContainsString('The Rabbit Hole', $body);
    }

    public function test_unknown_filter_reads_as_no_filter(): void
    {
        $this->seedFilterFixture();
        $plain = $this->app->handle(new Request('GET', '/browse/recent', [], [], []))->body;
        $junk = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'banana'], [], []))->body;
        $this->assertSame($plain, $junk, 'an off-whitelist filter is the unfiltered page');
        $this->assertStringContainsString('The Long Finish', $junk);
        $this->assertStringContainsString('The Rabbit Hole', $junk);
    }

    public function test_filter_chips_render_with_the_active_chip_filled(): void
    {
        $this->seedFilterFixture();
        $body = $this->app->handle(new Request('GET', '/browse/recent', [], [], []))->body;
        $this->assertStringContainsString('<nav class="filter-chips"', $body);
        $this->assertStringContainsString('aria-label="Filter stories"', $body);
        // All is the bare URL; the others carry their facet
        $this->assertStringContainsString('<a class="chip is-active" href="/browse/recent" aria-current="true">All</a>', $body);
        $this->assertStringContainsString('<a class="chip" href="/browse/recent?filter=complete">Complete</a>', $body);
        $this->assertStringContainsString('<a class="chip" href="/browse/recent?filter=wip">In progress</a>', $body);
        $this->assertStringContainsString('<a class="chip" href="/browse/recent?filter=under10k">Under 10k</a>', $body);
        // the active chip moves with the facet
        $filtered = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'complete'], [], []))->body;
        $this->assertStringContainsString('<a class="chip is-active" href="/browse/recent?filter=complete" aria-current="true">Complete</a>', $filtered);
        $this->assertStringNotContainsString('aria-current="true">All</a>', $filtered);
        // All clears every facet: the only way back to the unfiltered list
        $this->assertStringContainsString('<a class="chip" href="/browse/recent">All</a>', $filtered);
        // chips are recent-screen only; the category listing never renders them
        $category = $this->app->handle(new Request('GET', '/browse/category/general', [], [], []))->body;
        $this->assertStringNotContainsString('filter-chips', $category);
    }

    public function test_chip_links_reset_the_page_and_pager_links_preserve_the_filter(): void
    {
        $this->seedFilterFixture();
        $body = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'complete', 'page' => '3'], [], []))->body;
        // a chip link never carries ?page= (page resets to 1 with the facet)
        foreach (['href="/browse/recent"', 'href="/browse/recent?filter=complete"',
                  'href="/browse/recent?filter=wip"', 'href="/browse/recent?filter=under10k"'] as $chip) {
            $this->assertStringContainsString($chip, $body);
        }
        $this->assertStringNotContainsString('?filter=complete&amp;page=1"', $body, 'no chip URL carries page 1');
        // the pager keeps the active facet on both directions (a full page 2
        // of complete works at one per page has both neighbours)
        $first = $this->pagedApp()->handle(new Request('GET', '/browse/recent', ['filter' => 'complete'], [], []))->body;
        $this->assertStringContainsString('href="/browse/recent?filter=complete&amp;page=2"', $first, 'the Older link keeps the filter');
        $second = $this->pagedApp()->handle(new Request('GET', '/browse/recent', ['filter' => 'complete', 'page' => '2'], [], []))->body;
        $this->assertStringContainsString('href="/browse/recent?filter=complete&amp;page=1"', $second, 'the Newer link keeps the filter');
        // page 3 sits past the two complete works: Newer only, never Older
        $this->assertStringContainsString('href="/browse/recent?filter=complete&amp;page=2"', $body, 'the Newer link keeps the filter');
        $this->assertStringNotContainsString('href="/browse/recent?filter=complete&amp;page=4"', $body, 'no Older link past the end');
        // unfiltered pages keep the plain pager URL
        $plain = $this->pagedApp()->handle(new Request('GET', '/browse/recent', ['page' => '2'], [], []))->body;
        $this->assertStringContainsString('href="/browse/recent?page=1"', $plain);
        $this->assertStringContainsString('href="/browse/recent?page=3"', $plain);
    }

    public function test_cards_close_their_li_and_carry_the_comp_structure(): void
    {
        $this->seedFilterFixture();
        $body = $this->app->handle(new Request('GET', '/browse/recent', [], [], []))->body;
        // the pre-redesign bug: the story loop never closed its <li>
        $this->assertSame(preg_match_all('/<li[\s>]/', $body), preg_match_all('/<\/li>/', $body),
            'every listing li closes');
        // the M6 card: serif title link, strong byline, meta with the status
        // badge, summary clamped to two lines
        $this->assertStringContainsString('<a class="story-title" href="/story/view/the-rabbit-hole">The Rabbit Hole</a>', $body);
        $this->assertStringContainsString('<strong>Demo Author</strong>', $body);
        $this->assertStringContainsString('<span class="badge">In progress</span>', $body);
        $this->assertStringContainsString('5,000 words', $body);
        $this->assertStringContainsString('class="summary clamp-2"', $body);
    }

    /** The chip fixture: the filter fixture (Rabbit Hole in General, WIP,
     *  under 10k; The Long Finish completed, over 10k, no category) plus a
     *  third story that is completed AND in General, so cat and filter can
     *  each discriminate and their combination has a unique survivor. */
    private function seedChipFixture(): void
    {
        $this->seedFilterFixture();
        $db = new Database($this->dsn);
        $db->query("INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, updated_at) VALUES (?, ?, ?, 1, 1, 1, 1, 15000, ?)",
            ['The Last Ember', 'the-last-ember', 'Ash, and what survived it.', '2026-09-06T10:00:00Z']);
        $db->query('INSERT INTO story_categories (story_id, category_id)
                    VALUES ((SELECT id FROM stories WHERE slug = ?), (SELECT id FROM categories WHERE slug = ?))',
            ['the-last-ember', 'general']);
    }

    public function test_recent_renders_category_chips_from_the_fold_in_one_query(): void
    {
        $db = $this->app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = $this->app->handle(new Request('GET', '/browse/recent', [], [], []));
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        // the chip rides the listing fold: the category NAME as the label,
        // the slug in the href, no second query for the chip row
        $this->assertStringContainsString('<a class="chip" href="/browse/recent?cat=general">General</a>', $res->body);
        $this->assertLessThanOrEqual(1, $queries, "the chip row cost {$queries} queries; it must fold into the listing");
    }

    public function test_cat_param_narrows_the_listing(): void
    {
        $this->seedChipFixture();
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['cat' => 'general'], [], []));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('The Last Ember', $res->body);
        $this->assertStringNotContainsString('The Long Finish', $res->body, 'cat=general must drop the uncategorized story');
    }

    public function test_cat_array_value_reads_as_unfiltered_not_typeerror(): void
    {
        $this->seedChipFixture();
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['cat' => ['x']], [], []));
        $this->assertSame(200, $res->status, 'cat[]=x must not TypeError the listing into a 500');
        // the array value reads as unfiltered: all three stories render
        $this->assertStringContainsString('The Rabbit Hole', $res->body);
        $this->assertStringContainsString('The Long Finish', $res->body);
    }

    public function test_unknown_cat_renders_the_no_category_empty_state_with_the_all_chip(): void
    {
        $this->seedChipFixture();
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['cat' => 'nope'], [], []));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('No stories in this category.', $res->body);
        // the All chip stays on the empty page (and nothing is active: the
        // bound cat matched no chip)
        $this->assertStringContainsString('<a class="chip" href="/browse/recent">All</a>', $res->body);
        $this->assertStringNotContainsString('aria-current="true"', $res->body);
    }

    public function test_category_chip_is_active_when_its_cat_is_bound(): void
    {
        $this->seedChipFixture();
        $body = $this->app->handle(new Request('GET', '/browse/recent', ['cat' => 'general'], [], []))->body;
        $this->assertStringContainsString('<a class="chip is-active" href="/browse/recent?cat=general" aria-current="true">General</a>', $body);
        $this->assertStringNotContainsString('aria-current="true">All</a>', $body);
    }

    public function test_pager_links_preserve_cat_and_filter(): void
    {
        $this->seedChipFixture();
        $first = $this->pagedApp()->handle(new Request('GET', '/browse/recent', ['cat' => 'general', 'filter' => 'complete'], [], []))->body;
        $this->assertStringContainsString('href="/browse/recent?cat=general&amp;filter=complete&amp;page=2"', $first, 'the Older link keeps cat and filter');
        $second = $this->pagedApp()->handle(new Request('GET', '/browse/recent', ['cat' => 'general', 'filter' => 'complete', 'page' => '2'], [], []))->body;
        $this->assertStringContainsString('href="/browse/recent?cat=general&amp;filter=complete&amp;page=1"', $second, 'the Newer link keeps cat and filter');
    }

    public function test_cat_composes_with_filter(): void
    {
        $this->seedChipFixture();
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['cat' => 'general', 'filter' => 'complete'], [], []));
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('The Last Ember', $res->body);
        $this->assertStringNotContainsString('The Rabbit Hole', $res->body, 'the WIP story must drop under cat+complete');
        $this->assertStringNotContainsString('The Long Finish', $res->body, 'the uncategorized story must drop under cat+complete');
    }

    public function test_continue_pill_renders_for_the_member_with_progress_and_never_for_guests(): void
    {
        $db = new Database($this->dsn);
        $db->query("UPDATE stories SET word_count = 5000 WHERE slug = 'the-rabbit-hole'");
        $db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 1, 'One', 'x', 1, 2500)");
        $db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (1, 2, 'Two', 'y', 1, 2500)");
        $db->query("INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)", ['reader@x.test', 'h', 'Reader']);

        $guest = $this->app->handle(new Request('GET', '/browse/recent', [], [], []))->body;
        $this->assertStringNotContainsString('continue-pill', $guest, 'guests never see progress markup');

        // a member without a reading_history row: the LEFT JOIN yields NULL
        $noHistory = $this->client(2)->get('/browse/recent')->body;
        $this->assertStringNotContainsString('continue-pill', $noHistory);

        // the same member with progress on chapter 1 of 5000 words: 50%
        $db->query('INSERT INTO reading_history (user_id, story_id, last_position) VALUES (2, 1, 1)');
        $member = $this->client(2)->get('/browse/recent')->body;
        $this->assertStringContainsString('<a class="continue-pill" href="/story/read/the-rabbit-hole/1">Continue · I, 50%</a>', $member);
        // a resume point past the surviving chapters clamps to the last one
        // that still validates, like the story page (never a 404 link)
        $db->query('UPDATE reading_history SET last_position = 5 WHERE user_id = 2 AND story_id = 1');
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2">Continue', $this->client(2)->get('/browse/recent')->body);
        // a session revoked by a password change no longer reads the owner's
        // progress (qa-full /pentest): the listing treats it as a guest
        $c = $this->client(2);
        $db->query("UPDATE users SET password_hash = 'rotated' WHERE id = 2");
        $this->assertStringNotContainsString('continue-pill', $c->get('/browse/recent')->body);
        // Reading progress is not a mute feature: switching mute off must not
        // take the member's pill with it (qa-full /review, Codex).
        $db->query("INSERT OR REPLACE INTO feature_flags (key, enabled) VALUES ('mute', 0)");
        \App\Features::init($db, []); // the public/index.php init, on this DB
        $this->assertFalse(\App\Features::on('mute'));
        try {
            $this->assertStringContainsString('continue-pill', $this->client(2)->get('/browse/recent')->body);
        } finally {
            \App\Features::reset();
        }
    }

    /** Task 6 (infinite scroll, server contract only): ?fragment=1 renders
     *  ONLY the card loop, never a document. The seeded fixture plus a
     *  second story and a two-per-page app put a card on page 2. */
    private function fragmentApp(): App
    {
        return new App(array_merge($this->config(), ['items_per_page' => 2]));
    }

    private function seedSecondStory(string $title, string $slug, string $updatedAt): void
    {
        (new Database($this->dsn))->query(
            'INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, updated_at) VALUES (?, ?, ?, 1, 1, 1, ?)',
            [$title, $slug, 'A second story.', $updatedAt]
        );
    }

    public function test_recent_fragment_renders_only_the_card_loop(): void
    {
        $this->seedSecondStory('Middle Tale', 'middle-tale', '2026-09-02T10:00:00Z');
        $this->seedSecondStory('Older Tale', 'older-tale', '2026-08-30T10:00:00Z');
        $res = $this->fragmentApp()->handle(new Request('GET', '/browse/recent', ['page' => '2', 'fragment' => '1'], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<li class="story-card">', $res->body);
        $this->assertStringContainsString('/story/view/older-tale', $res->body);
        $this->assertStringNotContainsString('<html', $res->body, 'a fragment is never a document');
        $this->assertStringNotContainsString('<h1', $res->body, 'a fragment never carries the page heading');
        $this->assertStringNotContainsString('filter-chips', $res->body, 'a fragment never carries the chip row');
        $this->assertStringNotContainsString('site-head', $res->body, 'a fragment never carries the site header');
        // guests are byte-stable across calls: the fragment is cache-neutral
        $again = $this->fragmentApp()->handle(new Request('GET', '/browse/recent', ['page' => '2', 'fragment' => '1'], [], []));
        $this->assertSame($res->body, $again->body, 'guest fragment bodies must be byte-identical');
    }

    /** Category listings mount the same infinite module, so they answer
     *  ?fragment=1 with the bare card loop too. */
    public function test_category_fragment_renders_only_the_card_loop(): void
    {
        $this->seedSecondStory('Middle Tale', 'middle-tale', '2026-09-02T10:00:00Z');
        $this->seedSecondStory('Older Tale', 'older-tale', '2026-08-30T10:00:00Z');
        (new Database($this->dsn))->query("INSERT OR IGNORE INTO story_categories (story_id, category_id)
            SELECT s.id, (SELECT id FROM categories WHERE slug = 'general') FROM stories s");
        $res = $this->fragmentApp()->handle(new Request('GET', '/browse/category/general', ['page' => '2', 'fragment' => '1'], [], []));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('<li class="story-card">', $res->body);
        $this->assertStringNotContainsString('<html', $res->body, 'a fragment is never a document');
        $this->assertStringNotContainsString('site-head', $res->body);
        // member cards carry Continue pills: no shared cache keeps a
        // fragment, and no index takes the partial list (both listings)
        foreach (['/browse/category/general', '/browse/recent'] as $path) {
            $r = $this->fragmentApp()->handle(new Request('GET', $path, ['page' => '2', 'fragment' => '1'], [], []));
            $this->assertSame('private, no-store', $r->headers['Cache-Control'] ?? null, $path);
            $this->assertSame('noindex', $r->headers['X-Robots-Tag'] ?? null, $path);
        }
    }

    public function test_fragment_junk_page_params_coerce_to_page_one(): void
    {
        $one = $this->app->handle(new Request('GET', '/browse/recent', ['page' => '1', 'fragment' => '1'], [], []))->body;
        foreach (['0', 'abc', '-5'] as $junk) {
            $res = $this->app->handle(new Request('GET', '/browse/recent', ['page' => $junk, 'fragment' => '1'], [], []));
            $this->assertSame(200, $res->status);
            $this->assertSame($one, $res->body, "fragment page={$junk} must coerce to page 1");
        }
    }

    public function test_fragment_past_the_end_is_an_empty_card_list(): void
    {
        $res = $this->app->handle(new Request('GET', '/browse/recent', ['page' => '99', 'fragment' => '1'], [], []));
        $this->assertSame(200, $res->status, 'past-the-end fragments stay 200, the JS stop signal');
        $this->assertSame('', trim($res->body), 'an exhausted feed renders an empty card list');
        $this->assertStringNotContainsString('<li', $res->body);
    }

    public function test_fragment_page_two_carries_only_older_cards(): void
    {
        $this->seedSecondStory('Newer Tale', 'newer-tale', '2026-09-04T10:00:00Z');
        $this->seedSecondStory('Older Tale', 'older-tale', '2026-08-30T10:00:00Z');
        $app = $this->fragmentApp(); // per page 2: page 1 = 09-04 + 09-01, page 2 = 08-30
        $p1 = $app->handle(new Request('GET', '/browse/recent', ['fragment' => '1'], [], []))->body;
        $p2 = $app->handle(new Request('GET', '/browse/recent', ['page' => '2', 'fragment' => '1'], [], []))->body;
        $this->assertStringContainsString('2026-09-04', $p1);
        $this->assertStringNotContainsString('2026-08-30', $p1);
        $this->assertStringContainsString('2026-08-30', $p2, 'page 2 must be the OLDER stories');
        $this->assertStringNotContainsString('2026-09-04', $p2, 'newer cards never leak onto fragment page 2');
    }

    public function test_fragment_cards_share_the_page_card_bytes_exactly(): void
    {
        $this->seedFilterFixture();
        $page = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'complete'], [], []))->body;
        $frag = $this->app->handle(new Request('GET', '/browse/recent', ['filter' => 'complete', 'fragment' => '1'], [], []))->body;
        preg_match_all('/<li class="story-card">.*?<\/li>/s', $page, $pageCards);
        preg_match_all('/<li class="story-card">.*?<\/li>/s', $frag, $fragCards);
        $this->assertNotSame([], $pageCards[0], 'the filter really engaged on the page');
        $this->assertSame($pageCards[0], $fragCards[0],
            'fragment cards must be byte-identical to the page cards (links, filter and all)');
    }

    public function test_recent_list_carries_the_infinite_markup_contract(): void
    {
        // two completed plus the WIP rabbit: per page 2, both the unfiltered
        // and the complete-filtered page 1 come back FULL (has an older page)
        (new Database($this->dsn))->query(
            'INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, completed, word_count, updated_at) VALUES (?, ?, ?, 1, 1, 1, 1, 12000, ?)',
            ['Done Early', 'done-early', 'Wrapped sooner.', '2026-09-06T10:00:00Z']
        );
        $this->seedFilterFixture();
        $app = $this->fragmentApp();
        // a full page advertises the OLDER direction (newest-first feed)
        $body = $app->handle(new Request('GET', '/browse/recent', [], [], []))->body;
        $this->assertStringContainsString(
            '<ul class="story-list" data-js-module="infinite" data-next-url="/browse/recent?page=2" data-canonical="/browse/recent">',
            $body);
        // a short page has no older page: the empty next-url is the contract
        $end = $app->handle(new Request('GET', '/browse/recent', ['filter' => 'wip'], [], []))->body;
        $this->assertStringContainsString('data-next-url=""', $end);
        // the active filter rides data-next-url exactly like the visible Older link
        $filtered = $app->handle(new Request('GET', '/browse/recent', ['filter' => 'complete'], [], []))->body;
        $this->assertStringContainsString('data-next-url="/browse/recent?filter=complete&amp;page=2"', $filtered);
    }

}
