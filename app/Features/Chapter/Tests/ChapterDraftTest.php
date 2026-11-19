<?php // app/Features/Chapter/Tests/ChapterDraftTest.php
namespace App\Features\Chapter\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The real draft state: is_draft = 1 rides validated = 0, so it hides
 *  everywhere a scheduled chapter hides, and stays visible to its author. */
final class ChapterDraftTest extends TestCase
{
    private const DRAFT_WORD = 'zephyrquill';
    private string $path = '';
    private Database $db;
    private int $storyId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-draft-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, 1, (SELECT id FROM ratings LIMIT 1), 1, 3)', ['Fixture', 'fx', 's.']);
        $this->storyId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES (?, 1, 'One', 'First words here.', 1, 3)", [$this->storyId]);
        \App\Features::init($this->db, []);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(): array
    {
        return \App\Settings::apply([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-draft.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-draft-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'static_cache' => ['dir' => sys_get_temp_dir() . '/kiption-draft-cache-' . getmypid()],
        ]);
    }

    private function as(?int $uid): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $c = new TestClient(new App($this->config()));
        return $uid === null ? $c : $c->actingAs($uid);
    }

    private function post(string $path, array $data, int $uid = 1): \Kip\Http\Response
    {
        $c = $this->as($uid);
        return $c->post($path, $data + ['_token' => $c->csrfToken()]);
    }

    /** A draft chapter 2 saved through the real create route. */
    private function saveDraft(): void
    {
        $res = $this->post('/chapter/create/fx', ['title' => 'Hidden Two', 'content' => 'A ' . self::DRAFT_WORD . ' draft chapter body.', 'draft' => '1']);
        $this->assertSame(302, $res->status, $res->body);
    }

    private function row(int $pos = 2): array
    {
        return $this->db->one('SELECT validated, is_draft, publish_at FROM chapters WHERE story_id = ? AND position = ?', [$this->storyId, $pos]);
    }

    public function test_migration_adds_the_column_with_a_zero_default(): void
    {
        $cols = array_column($this->db->all("PRAGMA table_info('chapters')"), null, 'name');
        $this->assertArrayHasKey('is_draft', $cols);
        $this->assertSame('0', (string) $cols['is_draft']['dflt_value']);
        $this->assertSame(1, (int) $cols['is_draft']['notnull']);
        $this->assertSame(0, (int) $this->db->one('SELECT is_draft FROM chapters WHERE position = 1')['is_draft']);
        $this->assertFileExists(dirname(__DIR__, 3) . '/migrations/075_chapter_drafts.php');
    }

    public function test_a_new_chapter_without_the_draft_field_publishes_as_before(): void
    {
        $res = $this->post('/chapter/create/fx', ['title' => 'Live Two', 'content' => 'Plain words.']);
        $this->assertSame(302, $res->status);
        $this->assertSame(['validated' => 1, 'is_draft' => 0, 'publish_at' => null], $this->row());
    }

    public function test_saving_a_draft_stores_it_and_returns_to_the_editor_with_a_notice(): void
    {
        $res = $this->post('/chapter/create/fx', ['title' => 'Hidden Two', 'content' => 'Body ' . self::DRAFT_WORD . '.', 'draft' => '1']);
        $this->assertSame(302, $res->status);
        $this->assertSame('/chapter/edit/fx/2?saved=draft', $res->headers['Location'] ?? '');
        $this->assertSame(['validated' => 0, 'is_draft' => 1, 'publish_at' => null], $this->row());
        $page = $this->as(1)->get('/chapter/edit/fx/2', ['saved' => 'draft']);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Draft Saved. Only You Can See It.', $page->body);
    }

    public function test_a_draft_never_notifies_followers(): void
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('f@e.test', 'x', 'fan', ?, ?)", [date('c'), date('c')]);
        $fan = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, 1, 'email')", [$fan]);
        $this->saveDraft();
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM notifications WHERE user_id = ?', [$fan])['c']);
    }

    public function test_a_draft_is_hidden_from_every_reader_surface(): void
    {
        $this->saveDraft();
        $guest = $this->as(null);
        $this->assertSame(404, $guest->get('/story/read/fx/2')->status);
        $this->assertSame(200, $guest->get('/story/read/fx/1')->status);
        $story = $guest->get('/story/view/fx');
        $this->assertStringNotContainsString('Hidden Two', $story->body);
        $this->assertStringNotContainsString(self::DRAFT_WORD, $story->body);
        $this->assertStringNotContainsString('/story/read/fx/2', $story->body);
        foreach ([['/feed', []], ['/browse', []], ['/search', ['q' => self::DRAFT_WORD]], ['/sitemap.xml', []]] as [$url, $query]) {
            $body = $guest->get($url, $query)->body;
            $this->assertStringNotContainsString('Hidden Two', $body, $url);
            if ($url !== '/search') $this->assertStringNotContainsString(self::DRAFT_WORD, $body, $url); // the search box echoes the query
            $this->assertStringNotContainsString('/story/read/fx/2', $body, $url);
        }
        $this->assertStringNotContainsString('/story/view/fx', $guest->get('/search', ['q' => self::DRAFT_WORD])->body, 'the draft text finds no story');
        $this->assertStringContainsString('/story/view/fx', $guest->get('/search', ['q' => 'First'])->body, 'control: the live chapter is searchable');
        // the author sees it only on the editing side
        $this->assertSame(404, $this->as(1)->get('/story/read/fx/2')->status);
        $this->assertStringContainsString('Hidden Two', $this->as(1)->get('/story/edit/fx')->body);
        $this->assertStringContainsString('Draft', $this->as(1)->get('/story/edit/fx')->body);
    }

    public function test_a_draft_does_not_count_toward_words_or_the_moderation_queue(): void
    {
        $this->saveDraft();
        $this->assertSame(3, (int) $this->db->one('SELECT word_count FROM stories WHERE id = ?', [$this->storyId])['word_count']);
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM chapters ch JOIN stories s ON s.id = ch.story_id WHERE ' . \App\Repositories\AuthoringRepository::WAITING_CHAPTER)['c']);
        // a due schedule on a draft is never released, and no one can approve or remove it from the queue
        $this->db->query("UPDATE chapters SET publish_at = '2000-01-01T00:00:00Z' WHERE position = 2");
        $this->assertSame([], $repo->releaseDue());
        $id = (int) $this->db->one('SELECT id FROM chapters WHERE position = 2')['id'];
        $this->assertNull($repo->approveChapter($id));
        $this->assertNull($repo->removeChapter($id));
        $this->assertSame(1, (int) $this->row()['is_draft']);
        // approving the whole story leaves the draft alone
        $this->db->query('UPDATE stories SET validated = 0 WHERE id = ?', [$this->storyId]);
        $repo->approveStory($this->storyId);
        $this->assertSame(['validated' => 0, 'is_draft' => 1, 'publish_at' => '2000-01-01T00:00:00Z'], $this->row());
    }

    public function test_publishing_the_draft_flips_it_live_and_counts_words(): void
    {
        $this->saveDraft();
        $c = $this->as(1);
        $rev = \App\Tests\Support\ChapterForm::revision($c->get('/chapter/edit/fx/2')->body, 'base_revision');
        $res = $c->post('/chapter/update/fx/2', ['_token' => $c->csrfToken(), 'title' => 'Hidden Two', 'content' => 'A ' . self::DRAFT_WORD . ' draft chapter body.', 'base_revision' => $rev]);
        $this->assertSame(302, $res->status, $res->body);
        $this->assertSame(['validated' => 1, 'is_draft' => 0, 'publish_at' => null], $this->row());
        $this->assertSame(200, $this->as(null)->get('/story/read/fx/2')->status);
        $this->assertStringContainsString('Hidden Two', $this->as(null)->get('/story/view/fx')->body);
        $this->assertSame(3 + 5, (int) $this->db->one('SELECT word_count FROM stories WHERE id = ?', [$this->storyId])['word_count']);
    }

    public function test_a_scheduled_publish_from_a_draft_waits(): void
    {
        $this->saveDraft();
        $c = $this->as(1);
        $rev = \App\Tests\Support\ChapterForm::revision($c->get('/chapter/edit/fx/2')->body, 'base_revision');
        $c->post('/chapter/update/fx/2', ['_token' => $c->csrfToken(), 'title' => 'T', 'content' => 'Words.', 'publish_at' => '2099-01-01T00:00', 'base_revision' => $rev]);
        $this->assertSame(['validated' => 0, 'is_draft' => 0, 'publish_at' => '2099-01-01T00:00:00Z'], $this->row());
    }

    public function test_a_live_chapter_refuses_draft_and_keeps_the_text_and_readers(): void
    {
        $c = $this->as(1);
        $rev = \App\Tests\Support\ChapterForm::revision($c->get('/chapter/edit/fx/1')->body, 'base_revision');
        $res = $c->post('/chapter/update/fx/1', ['_token' => $c->csrfToken(), 'title' => 'One', 'content' => 'Edited words kept on screen.', 'draft' => '1', 'base_revision' => $rev]);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString(\App\Lang::t('chapter.live_no_draft'), $res->body);
        $this->assertStringContainsString('Edited words kept on screen.', $res->body, 'the writer text must come back');
        $this->assertSame(0, (int) $this->row(1)['is_draft']);
        $this->assertSame(200, $this->as(null)->get('/story/read/fx/1')->status);
    }

    public function test_an_invalid_schedule_shows_the_schedule_error_and_saves_nothing(): void
    {
        $c = $this->as(1);
        $rev = \App\Tests\Support\ChapterForm::revision($c->get('/chapter/edit/fx/1')->body, 'base_revision');
        $before = $this->db->one('SELECT content FROM chapters WHERE position = 1')['content'];
        foreach (['tomorrow', '2099-13-45T99:99', '0', '2099-01-01 09:00'] as $bad) {
            $res = $c->post('/chapter/update/fx/1', ['_token' => $c->csrfToken(), 'title' => 'One', 'content' => 'Changed body.', 'publish_at' => $bad, 'base_revision' => $rev]);
            $this->assertSame(422, $res->status, $bad);
            $this->assertStringContainsString(\App\Lang::t('chapter.publish_invalid'), html_entity_decode($res->body, ENT_QUOTES), $bad);
            $this->assertSame($before, $this->db->one('SELECT content FROM chapters WHERE position = 1')['content'], $bad);
        }
        $count = (int) $this->db->one('SELECT COUNT(*) AS n FROM chapters')['n'];
        $res = $c->post('/chapter/create/fx', ['_token' => $c->csrfToken(), 'title' => 'N', 'content' => 'New body.', 'publish_at' => 'tomorrow', 'draft' => '1']);
        $this->assertSame(422, $res->status);
        $this->assertSame($count, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapters')['n']);
    }

    public function test_conflict_detection_covers_the_draft_flag(): void
    {
        $this->saveDraft();
        $c = $this->as(1);
        $rev = \App\Tests\Support\ChapterForm::revision($c->get('/chapter/edit/fx/2')->body, 'base_revision');
        // someone else publishes the draft after this form loaded
        $this->db->query('UPDATE chapters SET is_draft = 0, validated = 1 WHERE position = 2');
        $res = $c->post('/chapter/update/fx/2', ['_token' => $c->csrfToken(), 'title' => 'Hidden Two', 'content' => 'A ' . self::DRAFT_WORD . ' draft chapter body.', 'draft' => '1', 'base_revision' => $rev]);
        $this->assertSame(409, $res->status);
        $this->assertSame(0, (int) $this->row()['is_draft'], 'the stale save must not have flipped the flag back');
        $this->assertNotSame($rev, \App\ChapterRevision::of(['chapter_id' => 1, 'is_draft' => 1]));
        $this->assertNotSame(\App\ChapterRevision::of(['chapter_id' => 1, 'is_draft' => 0]), \App\ChapterRevision::of(['chapter_id' => 1, 'is_draft' => 1]));
    }

    public function test_the_json_save_returns_the_draft_editor_address_and_replays_it(): void
    {
        $data = ['title' => 'J', 'content' => 'Json draft body.', 'draft' => '1', 'save_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        $c = $this->as(1);
        $res = $c->post('/chapter/create/fx', $data + ['_token' => $c->csrfToken()], ['accept' => 'application/json']);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame('/chapter/edit/fx/2?saved=draft', json_decode($res->body, true)['redirect']);
        $c = $this->as(1);
        $again = $c->post('/chapter/create/fx', $data + ['_token' => $c->csrfToken()], ['accept' => 'application/json']);
        $this->assertSame($res->body, $again->body);
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) c FROM chapters WHERE story_id = ?', [$this->storyId])['c']);
    }

    public function test_the_form_data_carries_the_draft_flag_and_the_contents_list_in_one_statement(): void
    {
        $this->saveDraft();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count, publish_at) VALUES (?, 3, 'Later', 'x', 0, 7, '2099-01-01T00:00:00Z')", [$this->storyId]);
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        $n = 0;
        \App\Tests\Support\QueryTap::on($this->db, function () use (&$n): void { $n++; });
        $row = $repo->chapterFormData('fx', 2, 1);
        \App\Tests\Support\QueryTap::off($this->db);
        $this->assertSame(1, $n, 'chapterFormData is one statement, contents included');
        $this->assertSame(1, (int) $row['is_draft']);
        $list = json_decode($row['contents_json'], true);
        $this->assertSame([1, 2, 3], array_column($list, 'position'));
        $this->assertSame([0, 1, 0], array_map('intval', array_column($list, 'draft')));
        $this->assertSame([3, 7], [(int) $list[0]['words'], (int) $list[2]['words']]);
        $this->assertSame('2099-01-01T00:00:00Z', $list[2]['publish_at']);
    }

    public function test_the_editor_shows_nothing_for_a_stranger_and_the_view_gets_status_labels(): void
    {
        $this->saveDraft();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count, publish_at) VALUES (?, 3, 'Later', 'x', 0, 7, '2099-01-01T00:00:00Z')", [$this->storyId]);
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('s@e.test', 'x', 'stranger', ?, ?)", [date('c'), date('c')]);
        $stranger = (int) $this->db->lastInsertId();
        $this->assertSame(404, $this->as($stranger)->get('/chapter/edit/fx/2')->status);
        // the controller's Contents rows, read through reflection so this pins the view contract
        $app = new App($this->config());
        $ctl = (new \ReflectionClass(\App\Features\Chapter\ChapterController::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($ctl, 'contents');
        $json = (new \App\Repositories\AuthoringRepository($this->db))->chapterFormData('fx', 2, 1)['contents_json'];
        $rows = $m->invoke($ctl, $json, 2);
        $this->assertSame(['Published', 'Draft', 'Scheduled'], array_column($rows, 'status'));
        $this->assertSame([false, true, false], array_column($rows, 'current'));
        $this->assertSame(['position', 'title', 'words', 'publish_at', 'draft'], array_slice(array_keys($rows[0]), 0, 5));
    }

    public function test_the_editor_baseline_spells_scene_breaks_the_way_epigraph_does(): void
    {
        $this->db->query("UPDATE chapters SET content = ? WHERE story_id = ?", ["One.\n\n* * *\n\nTwo.\n\n***\n\nThree.", $this->storyId]);
        $body = $this->as(1)->get('/chapter/edit/fx/1')->body;
        self::assertMatchesRegularExpression('#<textarea[^>]*name="content"[^>]*>One\.\n\n---\n\nTwo\.\n\n---\n\nThree\.</textarea>#', $body);
        // Only the editor's baseline changes: the stored text stays as written until the owner saves.
        self::assertStringContainsString('* * *', (string) $this->db->one('SELECT content FROM chapters WHERE story_id = ?', [$this->storyId])['content']);
    }
}
