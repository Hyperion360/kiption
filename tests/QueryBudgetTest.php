<?php // tests/QueryBudgetTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class QueryBudgetTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-budget-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function config(): array
    {
        return [
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-budget-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-budget-upl'],
        ];
    }

    #[DataProvider('pages')]
    public function test_every_page_stays_inside_the_one_query_budget(string $page): void
    {
        [$path, $query] = array_pad(explode('?', $page, 2), 2, '');
        $get = [];
        if ($query !== '') parse_str($query, $get);
        $app = new App($this->config());
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = $app->handle(new Request('GET', $path, $get, [], []));
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status, $page);
        $this->assertLessThanOrEqual(1, $queries, "{$page} ran {$queries} content queries, budget is 1");
    }

    public static function pages(): array
    {
        return [['/'], ['/browse'], ['/browse/recent'],
                // C10: the recent-screen facets fold into the ONE listing
                // query as WHERE clauses; every filtered shape stays budget-1
                // (and cache-ineligible by the queryless rule).
                ['/browse/recent?filter=complete'], ['/browse/recent?filter=wip'], ['/browse/recent?filter=under10k'],
                // The category chip's bound facet folds into the same single
                // statement (the EXISTS probe is a bind, never a second query).
                ['/browse/recent?cat=general'],
                // Task 6: the infinite-scroll fragment renders ONLY the card
                // partial from the same ONE listing query; the query string
                // keeps every fragment cache-ineligible.
                ['/browse/recent?fragment=1'], ['/browse/recent?page=2&fragment=1'],
                ['/browse/category/general'],
                ['/story/view/the-rabbit-hole'], ['/story/read/the-rabbit-hole/1'], ['/story/read/the-rabbit-hole/3'],
                ['/story/read/after-hours/1'], // adult story, cookieless: the age-gate render is a page shape too
                // The whole-work view rides the same one-query fold (finding 6:
                // the seed story backs the row; no in-file probe needed).
                ['/story/whole/the-rabbit-hole'],
                // The download reuses wholeWork for both formats (finding 16:
                // a one-query render); the html variant joins pages() and the
                // epub variant rides the identical query path.
                ['/story/download/the-rabbit-hole/html'],
                ['/series/view/down-the-rabbit-hole'],
                // The challenges surfaces (Task 3): the index's one-query listing
                // and the seeded fixture's view fold (finding 14 pins both).
                ['/challenges'], ['/challenges/view/community-challenge'],
                // The seeded about page: a real pages row so the budget row
                // exercises the actual page render, not a 404.
                ['/page/view/about'],
                // News surfaces: the seeded Welcome row (id 1 on a fresh DB)
                // backs the item row.
                ['/news'], ['/news/view/1'],
                ['/user/view/demo-author'], ['/user/stories/demo-author'], ['/user/favorites/demo-author'],
                ['/browse/authors'], ['/browse/authors/b'],
                // Query-string surfaces stay budget-1 shapes (one query each) but are
                // cache-ineligible by the queryless rule: the static whitelist matches
                // paths only, so ?beta=/?sort= variants always render live.
                ['/browse/authors?beta=1'], ['/user/stories/demo-author?sort=alpha'],
                // The search fold carries the filter taxonomies AND the results
                // in ONE compound statement, query string and all.
                ['/search?q=wonderland'],
                // The toplists hub: all four sections ride one zero-bind compound.
                ['/top'],
                ['/feed'], ['/rss'],
                // The anchor-row feeds: ONE flat query each (the config read
                // never touches the database).
                ['/feed/author/demo-author'], ['/feed/category/general']];
    }

    public static function authPages(): array
    {
        return [['/story/new'], ['/story/edit/the-rabbit-hole'],
                ['/chapter/new/the-rabbit-hole'], ['/chapter/edit/the-rabbit-hole/2'],
                // C4: the member story view runs the progress-folded statement
                // (reading_history LEFT JOIN + the pct/minutes columns); the
                // member chapter read below carries the same fold plus the
                // titled TOC blob, both still one query.
                ['/story/view/the-rabbit-hole'],
                ['/story/read/the-rabbit-hole/1'],
                ['/series/new'], ['/series/edit/down-the-rabbit-hole'],
                // The contact form targets the OTHER seeded member: the shared login
                // here is demo-author, and self-contact 404s on GET (Task 8 ruling).
                ['/user/contact/betafriend'],
                ['/queue'], ['/notifications'], ['/favorites'],
                // The PM surfaces (Task 3): the inbox's pair fold and the
                // thread's anchor-row fold, each one statement; the send path
                // is a POST and never budget-bound. betafriend is the partner
                // (the shared login here is demo-author, and self-threads 404).
                ['/messages'], ['/messages/view/betafriend'],
                // The account fold's seven-branch compound, muted branch
                // included (the flag defaults on in tests): first-ever budget
                // pin for the page, landed with the Task-1 QA ruling.
                ['/account'],
                // The author stats dashboard: the acting user (demo-author) owns
                // both seeded stories, so the row exercises the real render; the
                // four scalar subqueries ride the single own-works query.
                ['/stats']];
    }

    #[DataProvider('authPages')]
    public function test_author_pages_stay_inside_the_one_query_budget(string $page): void
    {
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'demo@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        if ($page === '/queue') {
            $demoId = (int) $db->one('SELECT id FROM users WHERE email = ?', ['demo@example.test'])['id'];
            \App\Adminness::setRole($db, $demoId, 'moderator');
        }
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            if ($sql === 'INSERT INTO reading_history (user_id, story_id, last_position) VALUES (?, ?, ?)
             ON CONFLICT (user_id, story_id) DO UPDATE SET
                last_position = MAX(last_position, excluded.last_position),
                updated_at = excluded.updated_at') return; // progress upsert, the logged-in read shape's extra write
            if ($sql === 'UPDATE messages SET read_at = ? WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL') return; // thread-open mark-read, the PM shape's extra write (finding 4, the reading_history precedent)
            $queries++;
        });
        $res = $client->get($page);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status, $page);
        $this->assertLessThanOrEqual(1, $queries, "{$page} ran {$queries} content queries, budget is 1");
    }

    /** The language-filtered browse page renders its section ABOVE the categories,
     *  so its budget is 2 content queries: one categoriesWithCounts plus one
     *  storiesInLanguage (the plan text said 1, but the categories list stays on
     *  the filtered page by design; probe-verified before pinning). The query
     *  string keeps the shape cache-ineligible, which is covered elsewhere. */
    public function test_language_filtered_browse_stays_inside_its_two_query_budget(): void
    {
        $this->seedLanguageOnOneStory();
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'demo@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            if ($sql === 'INSERT INTO reading_history (user_id, story_id, last_position) VALUES (?, ?, ?)
             ON CONFLICT (user_id, story_id) DO UPDATE SET
                last_position = MAX(last_position, excluded.last_position),
                updated_at = excluded.updated_at') return; // progress upsert, the logged-in read shape's extra write
            $queries++;
        });
        $res = $client->get('/browse', ['language' => 'en']);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('Stories in en', $res->body); // the filter really engaged the listing
        $this->assertLessThanOrEqual(2, $queries, "/browse?language=en ran {$queries} content queries, budget is 2");
    }

    private function seedLanguageOnOneStory(): void
    {
        (new Database('sqlite:' . $this->path))
            ->query("UPDATE stories SET language = 'en' WHERE slug = 'the-rabbit-hole'");
    }

    /** Finding 4's Task-2 pin: a MEMBER-rendered /browse/recent with mute
     *  rows planted stays a 1-query render. The clause rides the same
     *  statement as a conditional fragment; it is never a second query. The
     *  body assertion proves the member path really engaged (the stories are
     *  gone for the muter), so the row cannot pass vacuously through the
     *  anonymous shape. */
    public function test_member_recent_listing_with_mutes_planted_stays_inside_the_one_query_budget(): void
    {
        (new Database('sqlite:' . $this->path))->query(
            'INSERT INTO muted (user_id, author_id)
             VALUES ((SELECT id FROM users WHERE penname = ?), (SELECT id FROM users WHERE penname = ?))',
            ['betafriend', 'Demo Author']
        );
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/browse/recent');
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('/story/view/the-rabbit-hole', $res->body, 'the muter really lost the listing rows');
        $this->assertLessThanOrEqual(1, $queries, "member /browse/recent ran {$queries} content queries, budget is 1");
    }

    /** C10's heaviest listing shape: a MEMBER render with an active filter,
     *  the mute clause, AND the progress fold (reading_history LEFT JOIN +
     *  derived read_pct) in the same single statement. The pill in the body
     *  proves the member path really engaged, so the row cannot pass
     *  vacuously through the anonymous shape. */
    public function test_member_recent_listing_with_filter_and_progress_stays_inside_the_one_query_budget(): void
    {
        (new Database('sqlite:' . $this->path))->query(
            'INSERT INTO reading_history (user_id, story_id, last_position)
             VALUES ((SELECT id FROM users WHERE penname = ?), (SELECT id FROM stories WHERE slug = ?), 2)',
            ['betafriend', 'the-rabbit-hole']
        );
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/browse/recent', ['filter' => 'under10k']);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('continue-pill', $res->body, 'the progress fold really engaged');
        $this->assertLessThanOrEqual(1, $queries, "member filtered /browse/recent ran {$queries} content queries, budget is 1");
    }

    /** The chip task's heaviest listing shape: a MEMBER render with a bound
     *  cat (the EXISTS probe) AND the progress fold in the same single
     *  statement. The pill proves the member path really engaged; the chip
     *  row decoding from cats_blob proves the fold really engaged; neither
     *  may cost a second query. */
    public function test_member_recent_listing_with_cat_and_progress_stays_inside_the_one_query_budget(): void
    {
        (new Database('sqlite:' . $this->path))->query(
            'INSERT INTO reading_history (user_id, story_id, last_position)
             VALUES ((SELECT id FROM users WHERE penname = ?), (SELECT id FROM stories WHERE slug = ?), 2)',
            ['betafriend', 'the-rabbit-hole']
        );
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/browse/recent', ['cat' => 'general']);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('continue-pill', $res->body, 'the progress fold really engaged');
        $this->assertStringContainsString('<a class="chip is-active" href="/browse/recent?cat=general" aria-current="true">General</a>', $res->body, 'the category chip really rendered from the fold');
        $this->assertLessThanOrEqual(1, $queries, "member cat-filtered /browse/recent ran {$queries} content queries, budget is 1");
    }
    /** Task 6's fragment shape of the same fold: a MEMBER fragment render
     *  (?fragment=1) carries the mute clause AND the progress pill in the
     *  card partial, still from the ONE listing query. The pill in the
     *  fragment body proves the member path engaged (no vacuous guest pass);
     *  the query string keeps the fragment cache-ineligible. */
    public function test_member_recent_fragment_with_progress_stays_inside_the_one_query_budget(): void
    {
        (new Database('sqlite:' . $this->path))->query(
            'INSERT INTO reading_history (user_id, story_id, last_position)
             VALUES ((SELECT id FROM users WHERE penname = ?), (SELECT id FROM stories WHERE slug = ?), 2)',
            ['betafriend', 'the-rabbit-hole']
        );
        $app = new App($this->config());
        $client = new \Kip\Testing\TestClient($app);
        $client->post('/auth/attempt', ['email' => 'beta@example.test', 'password' => 'password123']);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?') return; // auth-session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/browse/recent', ['page' => '1', 'fragment' => '1']);
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status);
        $this->assertStringNotContainsString('<html', $res->body, 'the fragment really is the card partial');
        $this->assertStringContainsString('continue-pill', $res->body, 'the progress fold really engaged');
        $this->assertLessThanOrEqual(1, $queries, "member /browse/recent fragment ran {$queries} content queries, budget is 1");
    }
}
