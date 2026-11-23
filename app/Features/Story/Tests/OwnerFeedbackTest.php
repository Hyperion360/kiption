<?php // app/Features/Story/Tests/OwnerFeedbackTest.php
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** Archive-owner requests: an Edit link on the work page, a switch that drops
 *  the 1-10 score, and a switch that hides Categories. */
#[Medium]
final class OwnerFeedbackTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $authorId = 0;
    private int $strangerId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-ofb-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->authorId = (int) $this->db->one("SELECT author_id AS id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('st@e.test', ?, 'stranger', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        $this->strangerId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-ofb-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-ofb-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function setting(string $key, string $value): void
    {
        \App\Features::init($this->db, []); // the front controller does this at boot; tests do it by hand
        \App\Settings::put($key, $value);
    }

    public function test_the_author_sees_an_edit_link_on_the_work_page(): void
    {
        $this->assertStringContainsString('href="/story/edit/the-rabbit-hole"', $this->client($this->authorId)->get('/story/view/the-rabbit-hole')->body);
    }

    public function test_strangers_and_guests_see_no_edit_link(): void
    {
        $this->assertStringNotContainsString('/story/edit/the-rabbit-hole', $this->client($this->strangerId)->get('/story/view/the-rabbit-hole')->body);
        $this->assertStringNotContainsString('/story/edit/the-rabbit-hole', $this->client()->get('/story/view/the-rabbit-hole')->body);
    }

    public function test_scores_are_on_by_default(): void
    {
        $body = $this->client($this->strangerId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('rating-pips', $body);
    }

    public function test_scores_off_hides_the_pips_and_the_stored_score(): void
    {
        $this->client($this->strangerId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Lovely.', 'rating' => '8', 'guest_name' => '']);
        $this->setting('review_scores_on', '0');
        $body = $this->client($this->strangerId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('Lovely.', $body);
        $this->assertStringNotContainsString('rating-pips', $body);
        $this->assertStringNotContainsString('8/10', $body);
    }

    public function test_scores_off_stores_no_rating_and_requires_words(): void
    {
        $this->setting('review_scores_on', '0');
        $this->setting('rating_only', '1');
        $res = $this->client($this->strangerId)->postWithToken('/review/add/the-rabbit-hole', ['body' => '', 'rating' => '9', 'guest_name' => '']);
        $this->assertSame(422, $res->status);
        $this->client($this->strangerId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Nice work.', 'rating' => '9', 'guest_name' => '']);
        $row = $this->db->one("SELECT rating FROM reviews WHERE body = 'Nice work.'");
        $this->assertNull($row['rating']);
    }

    public function test_categories_off_hides_the_story_form_fieldset(): void
    {
        $this->assertStringContainsString('name="categories[]"', $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body);
        $this->setting('categories_on', '0');
        $this->assertStringNotContainsString('name="categories[]"', $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body);
    }

    // Regression: found by /qa on 2026-11-22, the header Browse panel still listed categories with the switch off.
    public function test_categories_off_hides_the_header_browse_panel_categories(): void
    {
        $this->assertStringContainsString('class="bp-cats"', $this->client($this->strangerId)->get('/story/view/the-rabbit-hole')->body);
        $this->setting('categories_on', '0');
        $body = $this->client($this->strangerId)->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringNotContainsString('class="bp-cats"', $body);
        $this->assertStringContainsString('bp-grid bp-grid-two', $body);
    }

    public function test_saving_with_categories_off_keeps_the_stories_categories(): void
    {
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $before = (int) $this->db->one('SELECT COUNT(*) n FROM story_categories WHERE story_id = ?', [$sid])['n'];
        $this->assertGreaterThan(0, $before, 'fixture story has categories');
        $this->setting('categories_on', '0');
        $title = (string) $this->db->one('SELECT title FROM stories WHERE id = ?', [$sid])['title'];
        $rating = (int) $this->db->one('SELECT rating_id FROM stories WHERE id = ?', [$sid])['rating_id'];
        $res = $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', ['title' => $title, 'summary' => 'Edited summary text here.', 'rating_id' => (string) $rating]);
        $this->assertSame('Edited summary text here.', $this->db->one('SELECT summary FROM stories WHERE id = ?', [$sid])['summary'], 'status ' . $res->status);
        $after = (int) $this->db->one('SELECT COUNT(*) n FROM story_categories WHERE story_id = ?', [$sid])['n'];
        $this->assertSame($before, $after);
    }

    public function test_categories_off_still_saves_a_category_bound_character(): void
    {
        $sid = (int) $this->db->one("SELECT id FROM stories WHERE slug = 'the-rabbit-hole'")['id'];
        $this->db->query('DELETE FROM story_categories WHERE story_id = ?', [$sid]);
        $cat = (int) $this->db->one('SELECT id FROM categories LIMIT 1')['id'];
        $this->db->query("INSERT INTO characters (category_id, name, slug) VALUES (?, 'Alice', 'alice-ofb')", [$cat]);
        $ch = (int) $this->db->lastInsertId();
        $this->setting('categories_on', '0');
        $title = (string) $this->db->one('SELECT title FROM stories WHERE id = ?', [$sid])['title'];
        $rating = (int) $this->db->one('SELECT rating_id FROM stories WHERE id = ?', [$sid])['rating_id'];
        $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', ['title' => $title, 'summary' => 'Edited again here.', 'rating_id' => (string) $rating, 'characters_shown' => '1', 'characters' => [(string) $ch]]);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM story_characters WHERE story_id = ? AND character_id = ?', [$sid, $ch])['n']);
    }
}
