<?php // app/Features/Series/Tests/SeriesIndexTest.php
namespace App\Features\Series\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** The /series index (comp nav surface): a guest page listing every series
 *  with title, owner penname, and its visible story count, paged like
 *  /browse/recent. The suite migrates WITHOUT the seeder so the empty-database
 *  shape is testable and every fixture row is explicit. */
final class SeriesIndexTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-series-index-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(array $extra = []): App
    {
        return new App(array_replace([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-series-index-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-series-index-upl'],
        ], $extra));
    }

    private function get(string $path, array $get = [], array $extra = []): \Kip\Http\Response
    {
        return $this->app($extra)->handle(new Request('GET', $path, $get, [], []));
    }

    private function userId(string $penname, string $email): int
    {
        $this->db->query(
            "INSERT INTO users (email, password_hash, penname, role, email_verified_at, approved_at) VALUES (?, ?, ?, 'validated_author', ?, ?)",
            [$email, password_hash('password123', PASSWORD_DEFAULT), $penname, date('c'), date('c')]
        );
        return (int) $this->db->lastInsertId();
    }

    private function storyId(int $authorId, string $title, string $slug, int $validated = 1, ?string $deletedAt = null): int
    {
        // The suite migrates without the seeder, so the one rating row the
        // FK needs comes in here (idempotent, Teen, the seeder's own shape).
        $this->db->query("INSERT OR IGNORE INTO ratings (label, is_adult, warning_text, position) VALUES ('Teen', 0, '', 2)");
        $ratingId = (int) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'];
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, \'\', ?, ?, ?, 100)',
            [$title, $slug, $authorId, $ratingId, $validated]);
        $id = (int) $this->db->lastInsertId();
        if ($deletedAt !== null) $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ?', [$deletedAt, $id]);
        return $id;
    }

    private function seriesId(int $ownerId, string $title, string $slug): int
    {
        $this->db->query('INSERT INTO series (title, slug, owner_id) VALUES (?, ?, ?)', [$title, $slug, $ownerId]);
        return (int) $this->db->lastInsertId();
    }

    private function item(int $seriesId, int $storyId, int $confirmed = 1): void
    {
        $this->db->query('INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (?, ?, 1, ?)',
            [$seriesId, $storyId, $confirmed]);
    }

    public function test_index_renders_for_guests_without_any_feature_gate(): void
    {
        // ALWAYS-ON on purpose: series carries NO feature flag (there is no
        // 'series' flag name and the controller deliberately adds no
        // Features::guard), so guests get a plain 200 from the bare route.
        $owner = $this->userId('Demo Author', 'demo@e.test');
        $this->seriesId($owner, 'Down the Rabbit Hole', 'down-the-rabbit-hole');
        $res = $this->get('/series');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Down the Rabbit Hole', $res->body);
        $this->assertStringContainsString('href="/series/view/down-the-rabbit-hole"', $res->body);
        $this->assertStringContainsString('Demo Author', $res->body);
    }

    public function test_counts_only_confirmed_items_of_validated_live_stories(): void
    {
        $owner = $this->userId('Demo Author', 'demo@e.test');
        $sid = $this->seriesId($owner, 'Down the Rabbit Hole', 'down-the-rabbit-hole');
        $this->item($sid, $this->storyId($owner, 'Visible', 'visible'));                                   // counts
        $this->item($sid, $this->storyId($owner, 'Pending item', 'pending-item'), 0);                      // unconfirmed: never counts
        $this->item($sid, $this->storyId($owner, 'Unvalidated story', 'unvalidated-story', validated: 0)); // draft: never counts
        $this->item($sid, $this->storyId($owner, 'Deleted story', 'deleted-story', deletedAt: date('c'))); // soft-deleted: never counts
        $res = $this->get('/series');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('1 works', $res->body);   // common.works_count, the series-view idiom
        $this->assertStringNotContainsString('4 works', $res->body);
        $this->assertStringNotContainsString('2 works', $res->body);
        $this->assertStringNotContainsString('3 works', $res->body);
    }

    public function test_ordered_by_title_ascending(): void
    {
        $a = $this->userId('Alpha Pen', 'alpha@e.test');
        $z = $this->userId('Zebra Pen', 'zebra@e.test');
        $this->seriesId($z, 'Zebra Works', 'zebra-works');
        $this->seriesId($a, 'Alpha Sagas', 'alpha-sagas');
        $body = $this->get('/series')->body;
        $this->assertGreaterThan(0, strpos($body, 'Alpha Sagas'), 'Alpha Sagas renders');
        $this->assertGreaterThan(strpos($body, 'Alpha Sagas'), strpos($body, 'Zebra Works'), 'title ASC order');
    }

    public function test_paged_with_items_per_page_and_coerced_page_param(): void
    {
        $a = $this->userId('Alpha Pen', 'alpha@e.test');
        $this->seriesId($a, 'Alpha Sagas', 'alpha-sagas');
        $this->seriesId($a, 'Zebra Works', 'zebra-works');
        // items_per_page drives the window (the browse precedent), ?page picks it.
        $one = $this->get('/series', [], ['items_per_page' => 1]);
        $this->assertStringContainsString('Alpha Sagas', $one->body);
        $this->assertStringNotContainsString('Zebra Works', $one->body);
        $two = $this->get('/series', ['page' => '2'], ['items_per_page' => 1]);
        $this->assertStringContainsString('Zebra Works', $two->body);
        $this->assertStringNotContainsString('Alpha Sagas', $two->body);
        // Junk, zero, and negative pages coerce to page 1 (the max(1,(int)) rule).
        foreach ([['page' => 'junk'], ['page' => '0'], ['page' => '-3']] as $q) {
            $res = $this->get('/series', $q, ['items_per_page' => 1]);
            $this->assertSame(200, $res->status);
            $this->assertStringContainsString('Alpha Sagas', $res->body, 'coerced to page 1');
        }
        // Past the end: 200 with an empty list, never a 404 or a warning.
        $past = $this->get('/series', ['page' => '99'], ['items_per_page' => 1]);
        $this->assertSame(200, $past->status, $past->body);
        $this->assertStringNotContainsString('/series/view/', $past->body);
    }

    public function test_empty_database_renders_none_yet(): void
    {
        $res = $this->get('/series');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('No series yet.', $res->body); // series.none_yet
    }
}
