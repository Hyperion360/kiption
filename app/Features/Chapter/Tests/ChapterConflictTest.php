<?php // app/Features/Chapter/Tests/ChapterConflictTest.php
namespace App\Features\Chapter\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The editor's conflict screen (Kiption Editor v2 "Compare versions"): two prose
 *  cards with the differing paragraphs marked, Replace as the one native submit. */
final class ChapterConflictTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $storyId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-conflict-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, 1, (SELECT id FROM ratings LIMIT 1), 1, 9)', ['Fixture', 'fx', 's.']);
        $this->storyId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO chapters (story_id, position, title, content, notes_before, validated, word_count) VALUES (?, 1, 'One', ?, 'Hello readers.', 1, 9)", [$this->storyId, "Ilse arrived at dusk.\n\nNobody said anything.\n\nShe found the inn."]);
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
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-conflict.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-conflict-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'static_cache' => ['dir' => sys_get_temp_dir() . '/kiption-conflict-cache-' . getmypid()],
        ]);
    }

    private function as(int $uid = 1): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        return (new TestClient(new App($this->config())))->actingAs($uid);
    }

    private function post(string $path, array $data): \Kip\Http\Response
    {
        $c = $this->as();
        return $c->post($path, $data + ['_token' => $c->csrfToken()]);
    }

    /** Open the edit page, let another writer change the archive, then save the stale form. */
    private function conflict(array $mine, array $archive, string $pos = '1'): array
    {
        $base = \App\Tests\Support\ChapterForm::revision($this->as()->get('/chapter/edit/fx/' . $pos)->body);
        $sets = []; $args = [];
        foreach ($archive as $k => $v) { $sets[] = "$k = ?"; $args[] = $v; }
        $this->db->query('UPDATE chapters SET ' . implode(', ', $sets) . ' WHERE story_id = ? AND position = ?', [...$args, $this->storyId, (int) $pos]);
        $res = $this->post('/chapter/update/fx/' . $pos, $mine + ['base_revision' => $base, 'notes_before' => 'Hello readers.']);
        return [$res, $base];
    }

    private function dom(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        return new \DOMXPath($doc);
    }

    public function test_the_cards_mark_only_the_differing_paragraphs(): void
    {
        [$res] = $this->conflict(
            ['title' => 'One', 'content' => "Ilse arrived at dusk.\n\nNobody said anything. That was worse.\n\nShe found the inn."],
            ['content' => "Ilse arrived at dusk.\n\nNo one spoke.\n\nShe found the inn."]
        );
        $this->assertSame(409, $res->status);
        $x = $this->dom($res->body);
        $mine = $x->query('//*[@data-ed-version="mine"]//mark');
        $theirs = $x->query('//*[@data-ed-version="theirs"]//mark');
        $this->assertSame(1, $mine->length);
        $this->assertSame(1, $theirs->length);
        $this->assertStringContainsString('Nobody said anything. That was worse.', $mine->item(0)->textContent);
        $this->assertStringContainsString('No one spoke.', $theirs->item(0)->textContent);
        $this->assertStringContainsString('1 of 3 paragraph', $x->query('//*[@data-ed-version="mine"]//*[contains(@class,"ed-diff-count")]')->item(0)->textContent);
        $this->assertStringContainsString('Differs:', $mine->item(0)->textContent, 'a cue that does not rely on the tint');
        $this->assertSame('Your Text', trim($x->query('//*[@data-ed-version="mine"]//strong')->item(0)->textContent));
        $this->assertSame('Archive Version', trim($x->query('//*[@data-ed-version="theirs"]//strong')->item(0)->textContent));
    }

    public function test_the_replace_action_and_every_posted_field_survive_the_cards(): void
    {
        [$res, $base] = $this->conflict(['title' => 'Mine', 'content' => 'My words.'], ['content' => 'Their words.']);
        $x = $this->dom($res->body);
        $current = $this->db->one('SELECT * FROM chapters WHERE story_id = ? AND position = 1', [$this->storyId]);
        $replace = $x->query('//section[@data-ed-conflict]//button[@name="accept_revision"]');
        $this->assertSame(1, $replace->length);
        $this->assertSame(\App\ChapterRevision::of($current + ['chapter_id' => $current['id']]), $replace->item(0)->getAttribute('value'));
        $this->assertSame('Replace Archive with Your Text', trim($replace->item(0)->textContent));
        $this->assertSame($base, $x->query('//input[@name="base_revision"]')->item(0)->getAttribute('value'));
        foreach (['title', 'content', 'notes_before', 'notes_after', '_token'] as $name) {
            $this->assertGreaterThan(0, $x->query('//form[@id="chapter-editor-form"]//*[@name="' . $name . '"]')->length, $name);
        }
        $this->assertSame('/chapter/edit/fx/1', $x->query('//a[@data-ed-use-archive]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString('js-only', $x->query('//button[@data-ed-conflict-copy="mine"]')->item(0)->getAttribute('class'));
        $this->assertStringContainsString('ed-conflict', $x->query('//form')->item(0)->getAttribute('data-js-module'));
        $this->assertSame(0, $x->query('//*[@data-ed-conflict]//input[@name="draft"]')->length, 'a published chapter posts no draft flag');
        $ok = $this->post('/chapter/update/fx/1', ['title' => 'Mine', 'content' => 'My words.', 'base_revision' => $base, 'accept_revision' => $replace->item(0)->getAttribute('value')]);
        $this->assertSame(302, $ok->status);
        $this->assertSame('My words.', $this->db->one('SELECT content FROM chapters WHERE id = ?', [$current['id']])['content']);
    }

    public function test_a_title_only_conflict_marks_the_title_in_both_cards(): void
    {
        [$res] = $this->conflict(['title' => 'Mine', 'content' => "Ilse arrived at dusk.\n\nNobody said anything.\n\nShe found the inn."], ['title' => 'Theirs']);
        $x = $this->dom($res->body);
        $this->assertSame(1, $x->query('//*[@data-ed-version="mine"]//*[contains(@class,"ed-cmp-title")]//mark')->length);
        $this->assertSame(1, $x->query('//*[@data-ed-version="theirs"]//*[contains(@class,"ed-cmp-title")]//mark')->length);
        $this->assertSame(0, $x->query('//*[@data-ed-version]//*[contains(@class,"ed-prose")]//mark')->length);
        $this->assertStringContainsString('The text matches', $res->body);
    }

    public function test_writer_text_is_escaped_in_the_cards(): void
    {
        [$res] = $this->conflict(['title' => 'One', 'content' => '<script>alert(1)</script>'], ['content' => 'Safe.']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $res->body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $res->body);
    }

    public function test_a_draft_chapter_stays_a_draft_when_replace_is_used(): void
    {
        $this->db->query('UPDATE chapters SET is_draft = 1, validated = 0 WHERE story_id = ?', [$this->storyId]);
        [$res, $base] = $this->conflict(['title' => 'One', 'content' => 'Draft mine.', 'draft' => '1'], ['content' => 'Draft theirs.']);
        $this->assertSame(409, $res->status);
        $x = $this->dom($res->body);
        $flag = $x->query('//*[@data-ed-conflict]//input[@type="hidden"][@name="draft"]');
        $this->assertSame(1, $flag->length);
        $this->assertSame('1', $flag->item(0)->getAttribute('value'));
        $replace = $x->query('//button[@name="accept_revision"]')->item(0)->getAttribute('value');
        // The browser posts the hidden flag with the Replace button.
        $ok = $this->post('/chapter/update/fx/1', ['title' => 'One', 'content' => 'Draft mine.', 'draft' => '1', 'base_revision' => $base, 'accept_revision' => $replace]);
        $this->assertSame(302, $ok->status);
        $row = $this->db->one('SELECT is_draft, validated, content FROM chapters WHERE story_id = ?', [$this->storyId]);
        $this->assertSame([1, 0, 'Draft mine.'], [(int) $row['is_draft'], (int) $row['validated'], $row['content']]);
    }

    public function test_a_draft_story_conflict_keeps_the_story_fields_in_the_form(): void
    {
        $this->db->query('UPDATE stories SET is_draft = 1, validated = 0 WHERE id = ?', [$this->storyId]);
        $rating = (int) $this->db->one('SELECT id FROM ratings LIMIT 1')['id'];
        [$res] = $this->conflict(['title' => 'One', 'content' => 'Mine.', 'story_title' => 'Renamed', 'summary' => 'It begins.', 'rating_id' => (string) $rating, 'language' => 'en'], ['content' => 'Theirs.']);
        $this->assertSame(409, $res->status);
        $x = $this->dom($res->body);
        $this->assertSame('draft-story', $x->query('//form[@id="chapter-editor-form"]')->item(0)->getAttribute('data-ed-mode'));
        $this->assertSame('Renamed', $x->query('//input[@name="story_title"]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $x->query('//button[@name="accept_revision"]')->length);
        $this->assertSame(1, $x->query('//*[@data-ed-version="mine"]//mark')->length);
    }

    public function test_the_page_asks_the_content_database_once_for_the_cards(): void
    {
        $base = \App\Tests\Support\ChapterForm::revision($this->as()->get('/chapter/edit/fx/1')->body);
        $this->db->query("UPDATE chapters SET content = 'Elsewhere.' WHERE story_id = ?", [$this->storyId]);
        $reads = 0;
        \App\Tests\Support\QueryTap::on($this->db, static function (string $sql) use (&$reads): void {
            if (str_contains($sql, 's.title AS story_title')) $reads++;
        });
        $app = new App($this->config());
        $app->container->instance(Database::class, $this->db);
        $client = (new TestClient($app))->actingAs(1);
        try {
            $res = $client->post('/chapter/update/fx/1', ['title' => 'One', 'content' => 'Mine.', 'base_revision' => $base, '_token' => $client->csrfToken()]);
        } finally {
            \App\Tests\Support\QueryTap::off($this->db);
        }
        $this->assertSame(409, $res->status);
        $this->assertSame(1, $reads);
    }

    public function test_the_stylesheet_script_and_strings_are_wired(): void
    {
        $root = dirname(__DIR__, 4);
        $css = (string) file_get_contents($root . '/public/assets/reader.css');
        foreach (['.ed-versions', '.ed-version-head', '.ed-mark', '.ed-prose', '.ed-conflict-btn', '[data-ed-conflicted]'] as $selector) {
            $this->assertStringContainsString($selector, $css, $selector);
        }
        $this->assertStringContainsString('color-mix(in srgb, var(--accent) 18%, transparent)', $css);
        $this->assertStringContainsString("['ed-conflict']", (string) file_get_contents($root . '/public/assets/app.js'));
        $lang = require $root . '/app/lang/en.php';
        foreach (['ed.conflict_title', 'ed.conflict_lede', 'ed.conflict_mine_meta', 'ed.conflict_theirs_meta', 'ed.conflict_copy_mine', 'ed.conflict_copy_theirs', 'chapter.use_archive', 'chapter.replace_shown'] as $key) {
            $this->assertArrayHasKey($key, $lang, $key);
            $this->assertStringNotContainsString('—', $lang[$key], $key);
        }
    }
}
