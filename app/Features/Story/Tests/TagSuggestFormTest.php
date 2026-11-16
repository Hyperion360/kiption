<?php // app/Features/Story/Tests/TagSuggestFormTest.php
namespace App\Features\Story\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Slice 4 Task 4.3: suggestions ride the story save and the story form. */
final class TagSuggestFormTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-suggform-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        \App\Features::reset(); // the static settings memo must not leak into later suites
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-suggform-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-suggform-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $overrides);
    }

    private function clientAs(int $userId): TestClient
    {
        return (new TestClient(new App($this->config())))->actingAs($userId);
    }

    private function setPolicy(string $policy): void
    {
        // The row direct, then point the Features static at this database:
        // the HTTP front controller runs Features::init per request, but the
        // TestClient drives App::handle directly, so the test owns the init.
        $this->db->query('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', ['tag_policy', $policy]);
        \App\Features::init($this->db, []);
    }

    private function genreTypeId(): int
    {
        return (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'genre'")['id'];
    }

    private function ratingId(): int
    {
        return (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
    }

    private function categoryId(): int
    {
        return (int) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
    }

    private function secondMember(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('sf-m2@e.test', ?, 'sfmember2', ?, ?)",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function makeStory(int $authorId = 1, string $title = 'Suggest Ride'): int
    {
        $this->db->query('INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES (?, ?, ?, ?, 1)',
            [$title, 'suggest-ride', $authorId, $this->ratingId()]);
        return (int) $this->db->lastInsertId();
    }

    public function test_story_form_shows_suggest_boxes_only_under_a_policy_that_allows_them(): void
    {
        $genre = $this->genreTypeId();
        // off (the default): no suggest inputs anywhere
        $new = $this->clientAs(1)->get('/story/new')->body;
        $this->assertStringNotContainsString('name="suggest', $new, 'off renders no suggest input');
        $this->setPolicy('suggest');
        $new = $this->clientAs(1)->get('/story/new')->body;
        $this->assertStringContainsString('name="suggest[' . $genre . ']"', $new, 'suggest renders one input per type');
        $this->setPolicy('free');
        $new = $this->clientAs(1)->get('/story/new')->body;
        $this->assertStringContainsString('name="suggest[' . $genre . ']"', $new, 'free renders the input too');
    }

    public function test_empty_tag_types_still_offer_suggestions(): void
    {
        $this->db->query('DELETE FROM story_tags');
        $this->db->query('DELETE FROM tags');
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $content = (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'content'")['id'];

        $new = $this->clientAs(1)->get('/story/new');
        $this->assertSame(200, $new->status);
        $this->assertStringContainsString('name="suggest[' . $genre . ']"', $new->body);
        $this->assertStringContainsString('name="suggest[' . $content . ']"', $new->body);

        $created = $this->clientAs(1)->postWithToken('/story/create', [
            'title' => 'First Tag Proposal', 'summary' => 'A tale.',
            'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'suggest' => [$genre => 'First Genre'],
        ]);
        $this->assertSame(302, $created->status);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM tag_suggestions WHERE name = 'First Genre' AND status = 'pending'")['c']);
    }

    public function test_posting_a_suggestion_with_the_story_stores_it_pending(): void
    {
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $res = $this->clientAs(1)->postWithToken('/story/create', [
            'title' => 'Suggest Carrier', 'summary' => 'A tale.', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'suggest' => [$genre => 'Zzyzx Marker'],
        ]);
        $this->assertSame(302, $res->status);
        $row = $this->db->one("SELECT * FROM tag_suggestions WHERE name = 'Zzyzx Marker'");
        $this->assertNotNull($row, 'the suggestion is stored');
        $this->assertSame('pending', $row['status']);
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'suggest-carrier'")['id'];
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$sid])['c'], 'pending tags attach to nothing');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) c FROM tags WHERE name = 'Zzyzx Marker'")['c']);
        // the redirect target form shows the waiting line (the author sees it)
        $edit = $this->clientAs(1)->get('/story/edit/suggest-carrier')->body;
        $this->assertStringContainsString('Zzyzx Marker', $edit);
    }

    public function test_pending_suggestion_is_visible_to_its_author_on_edit_and_to_an_admin_only(): void
    {
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $story = $this->makeStory(1);
        $other = $this->secondMember();
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$genre, 'Co Written Marker', 1, $story, 'pending', date('c')]);
        $author = $this->clientAs(1)->get('/story/edit/suggest-ride')->body;
        $this->assertStringContainsString('Co Written Marker', $author);
        // another member (a coauthor-less viewer would 404; a coauthor sees only their own rows)
        $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$story, $other]);
        $co = $this->clientAs($other)->get('/story/edit/suggest-ride')->body;
        $this->assertStringNotContainsString('Co Written Marker', $co, 'a coauthor who suggested nothing sees no one else’s rows');
        // the admin sees every row on the story
        $admin = $this->clientAs(1);
        $this->db->query("UPDATE users SET role = 'admin' WHERE id = 1");
        $view = $admin->get('/story/edit/suggest-ride')->body;
        $this->assertStringContainsString('Co Written Marker', $view, 'the admin sees the story’s suggestions');
    }

    public function test_ids_that_are_not_live_tags_attach_nothing(): void
    {
        $this->setPolicy('suggest');
        $max = (int) $this->db->one('SELECT COALESCE(MAX(id), 0) m FROM tags')['m'];
        $res = $this->clientAs(1)->postWithToken('/story/create', [
            'title' => 'Forged Ids', 'summary' => 'A tale.', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'tags' => [(string) ($max + 1000)],
        ]);
        $this->assertSame(302, $res->status, 'no error page for a forged tag id');
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'forged-ids'")['id'];
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$sid])['c']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c'], 'forged ids never become suggestions');
    }

    public function test_author_can_withdraw_a_pending_suggestion_but_not_someone_elses(): void
    {
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $story = $this->makeStory(1);
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 1, ?, ?, ?)',
            [$genre, 'Pull Me', $story, 'pending', date('c')]);
        $mine = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$genre, 'Not Mine', 2, $story, 'pending', date('c')]);
        $theirs = (int) $this->db->lastInsertId();
        $member2 = $this->secondMember();
        $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$story, $member2]);
        $res = $this->clientAs(1)->postWithToken('/story/update/suggest-ride', [
            'title' => 'Suggest Ride', 'summary' => 'A tale.', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'withdraw' => [(string) $mine, (string) $theirs],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertNull($this->db->one('SELECT id FROM tag_suggestions WHERE id = ?', [$mine]), 'the author withdrew their own');
        $this->assertNotNull($this->db->one('SELECT id FROM tag_suggestions WHERE id = ?', [$theirs]), 'another member row is kept');
    }

    public function test_editing_a_story_does_not_resubmit_or_duplicate_pending_names(): void
    {
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $story = $this->makeStory(1);
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 1, ?, ?, ?)',
            [$genre, 'Standing Marker', $story, 'pending', date('c')]);
        $res = $this->clientAs(1)->postWithToken('/story/update/suggest-ride', [
            'title' => 'Suggest Ride', 'summary' => 'A tale.', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'suggest' => [$genre => 'Standing Marker'],
        ]);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM tag_suggestions WHERE name = 'Standing Marker'")['c'], 'the UNIQUE holds, idempotent');
    }

    public function test_story_form_stays_one_query_for_author_and_admin(): void
    {
        $this->setPolicy('suggest');
        $genre = $this->genreTypeId();
        $story = $this->makeStory(1);
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, 1, ?, ?, ?)',
            [$genre, 'Budget Marker', $story, 'pending', date('c')]);
        foreach (['author', 'admin'] as $label) {
            $app = new App($this->config());
            $client = new TestClient($app);
            $client->post('/auth/attempt', ['email' => 'demo@example.test', 'password' => 'password123']);
            $db = $app->container->make(\Kip\Database::class);
            $queries = 0;
            \App\Tests\Support\QueryTap::on($db, function (string $sql) use (&$queries): void {
                if ($sql === 'SELECT password_hash FROM users WHERE id = ?' || $sql === 'SELECT password_hash, is_locked, role FROM users WHERE id = ?') return; // session validation, excluded by rule
                $queries++;
            });
            $res = $client->get('/story/edit/suggest-ride');
            \App\Tests\Support\QueryTap::off($db);
            $this->assertSame(200, $res->status, $label);
            $this->assertLessThanOrEqual(1, $queries, "{$label} form ran {$queries} queries, budget is 1");
        }
        $app = new App($this->config());
        $client = new TestClient($app);
        $client->post('/auth/attempt', ['email' => 'demo@example.test', 'password' => 'password123']);
        $db = $app->container->make(\Kip\Database::class);
        $queries = 0;
        \App\Tests\Support\QueryTap::on($db, function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?' || $sql === 'SELECT password_hash, is_locked, role FROM users WHERE id = ?') return;
            $queries++;
        });
        $res = $client->get('/story/new');
        \App\Tests\Support\QueryTap::off($db);
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "new form ran {$queries} queries, budget is 1");
    }

    public function test_a_free_policy_save_creates_the_tag_and_attaches_it(): void
    {
        $this->setPolicy('free');
        $genre = $this->genreTypeId();
        $res = $this->clientAs(1)->postWithToken('/story/create', [
            'title' => 'Free Carrier', 'summary' => 'A tale.', 'rating_id' => (string) $this->ratingId(),
            'categories' => [(string) $this->categoryId()],
            'suggest' => [$genre => 'Instant Marker'],
        ]);
        $this->assertSame(302, $res->status);
        $tag = $this->db->one("SELECT id FROM tags WHERE name = 'Instant Marker'");
        $this->assertNotNull($tag, 'free mode created the live tag');
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'free-carrier'")['id'];
        $this->assertSame((int) $tag['id'], (int) $this->db->one('SELECT tag_id FROM story_tags WHERE story_id = ?', [$sid])['tag_id']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM tag_suggestions')['c']);
    }
}
