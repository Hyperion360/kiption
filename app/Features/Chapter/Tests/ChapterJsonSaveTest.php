<?php // app/Features/Chapter/Tests/ChapterJsonSaveTest.php
namespace App\Features\Chapter\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The editor's save: Accept: application/json, a UUID save_key, a
 *  {redirect} reply, safe to retry, and the image-reference rules. */
final class ChapterJsonSaveTest extends TestCase
{
    private const JSON = ['accept' => 'application/json'];
    private const K1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const K2 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const IMG = '/uploads/0123456789abcdef.png';
    private string $path = '';
    private Database $db;
    private int $storyId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-cjs-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, word_count) VALUES (?, ?, ?, 1, (SELECT id FROM ratings LIMIT 1), 1, 10)', ['Fixture', 'fx', 's.']);
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

    private function as(int $uid): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        return (new TestClient(new App(\App\Settings::apply([
            'app_dir' => dirname(__DIR__, 4) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-cjs.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-cjs-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]))))->actingAs($uid);
    }

    private function jpost(int $uid, string $path, array $data): \Kip\Http\Response
    {
        $c = $this->as($uid);
        return $c->post($path, $data + ['_token' => $c->csrfToken()], self::JSON);
    }

    private function create(array $f = [], int $uid = 1): \Kip\Http\Response
    {
        return $this->jpost($uid, '/chapter/create/fx', $f + ['title' => 'New', 'content' => 'Four fresh words here.', 'notes_before' => '', 'notes_after' => '', 'save_key' => self::K1]);
    }

    private function chapters(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS n FROM chapters WHERE story_id = ?', [$this->storyId])['n'];
    }

    private function revision(): string
    {
        return \App\Tests\Support\ChapterForm::revision($this->as(1)->get('/chapter/edit/fx/1')->body, 'base_revision');
    }

    private function member(): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('m@e.test', 'x', 'm', ?, ?)", [date('c'), date('c')]);
        return (int) $this->db->lastInsertId();
    }

    private function mine(string $path, int $owner = 1): void
    {
        $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, created_at) VALUES (?, ?, ?, ?)', [$owner, $path, 'x', date('c')]);
    }

    public function test_json_create_replies_with_a_same_origin_redirect(): void
    {
        $r = $this->create();
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['redirect' => '/story/edit/fx'], json_decode($r->body, true));
        $this->assertSame(2, $this->chapters());
    }

    public function test_a_retry_with_the_same_key_and_content_writes_nothing_more(): void
    {
        $first = $this->create();
        \App\Settings::put('max_words', '2'); // the policy changed after the first commit
        $retry = $this->create();
        $this->assertSame(200, $retry->status);
        $this->assertSame($first->body, $retry->body);
        $this->assertSame(2, $this->chapters());
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapter_save_requests')['n']);
    }

    public function test_the_same_key_with_other_content_is_a_409(): void
    {
        $this->create();
        $r = $this->create(['content' => 'Different words entirely now.']);
        $this->assertSame(409, $r->status);
        $this->assertArrayHasKey('error', json_decode($r->body, true));
        $this->assertSame(2, $this->chapters());
    }

    public function test_a_failed_validation_does_not_use_up_the_key(): void
    {
        $bad = $this->create(['content' => '   ']);
        $this->assertSame(422, $bad->status);
        $this->assertArrayHasKey('error', json_decode($bad->body, true));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapter_save_requests')['n']);
        $this->assertSame(200, $this->create()->status);
    }

    public function test_json_update_retries_even_though_the_revision_moved(): void
    {
        $rev = $this->revision();
        $f = ['title' => 'One', 'content' => 'Edited words now here.', 'notes_before' => '', 'notes_after' => '', 'base_revision' => $rev, 'save_key' => self::K2];
        $first = $this->jpost(1, '/chapter/update/fx/1', $f);
        $this->assertSame(200, $first->status, $first->body);
        $this->assertSame(['redirect' => '/chapter/edit/fx/1?saved=1'], json_decode($first->body, true));
        $retry = $this->jpost(1, '/chapter/update/fx/1', $f); // base_revision is now stale
        $this->assertSame(200, $retry->status, $retry->body);
        $this->assertSame($first->body, $retry->body);
        // without the key the stale revision still refuses, as a JSON 409
        $stale = $this->jpost(1, '/chapter/update/fx/1', ['save_key' => '99999999-9999-4999-8999-999999999999'] + $f);
        $this->assertSame(409, $stale->status);
        $this->assertArrayHasKey('error', json_decode($stale->body, true));
    }

    public function test_missing_or_malformed_keys_and_strangers_get_json_errors(): void
    {
        $this->assertSame(422, $this->create(['save_key' => 'nope'])->status);
        $stranger = $this->member();
        $this->assertSame(404, $this->create([], $stranger)->status);
        $this->assertArrayHasKey('error', json_decode($this->create([], $stranger)->body, true));
        $this->assertSame(1, $this->chapters());
    }

    public function test_a_paused_site_answers_json(): void
    {
        \App\Settings::put('submissions_open', '0');
        $r = $this->create([], $this->member());
        $this->assertSame(403, $r->status);
        $this->assertArrayHasKey('error', json_decode($r->body, true));
    }

    public function test_a_plain_form_post_still_redirects_without_a_key(): void
    {
        $r = $this->as(1)->postWithToken('/chapter/create/fx', ['title' => 'N', 'content' => 'Plain form words.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $r->status);
        $this->assertSame('/story/edit/fx', $r->headers['Location']);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM chapter_save_requests')['n']);
    }

    public function test_unresolved_editor_placeholders_are_refused_for_forms_and_json(): void
    {
        foreach (['![a](epigraph-pending:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa)', '![a](blob:https://x/1)'] as $md) {
            $this->assertSame(422, $this->create(['content' => "Words\n\n$md"])->status);
            $this->assertSame(422, $this->as(1)->postWithToken('/chapter/create/fx', ['title' => 'N', 'content' => "Words\n\n$md", 'notes_before' => '', 'notes_after' => ''])->status);
        }
        $this->assertSame(1, $this->chapters());
    }

    public function test_images_obey_the_switch_the_library_and_alt_text(): void
    {
        $md = "Words here.\n\n![A map](" . self::IMG . ')';
        $this->assertSame(422, $this->create(['content' => $md])->status, 'switch off');
        \App\Settings::put('chapter_images_on', '1');
        $this->assertSame(422, $this->create(['content' => $md])->status, 'not in any library');
        $this->mine(self::IMG, 2);
        $this->assertSame(422, $this->create(['content' => $md])->status, 'another author\'s image');
        $this->db->query('DELETE FROM author_images WHERE owner_id = 2');
        $this->mine(self::IMG, 1);
        $this->assertSame(422, $this->create(['content' => "Words here.\n\n![ ](" . self::IMG . ')'])->status, 'empty alt');
        $this->assertSame(200, $this->create(['content' => $md])->status);
        $this->assertSame(2, $this->chapters());
    }

    public function test_an_unchanged_image_survives_prose_edits_while_the_switch_is_off(): void
    {
        $md = "Words here.\n\n![A map](" . self::IMG . ')';
        $this->db->query('UPDATE chapters SET content = ? WHERE story_id = ?', [$md, $this->storyId]);
        $f = ['title' => 'One', 'content' => $md . "\n\nMore words.", 'notes_before' => '', 'notes_after' => '', 'base_revision' => $this->revision(), 'save_key' => self::K2];
        $this->assertSame(200, $this->jpost(1, '/chapter/update/fx/1', $f)->status);
        // a second copy of the same image is an addition and is refused
        $f['content'] = $md . "\n\n![Again](" . self::IMG . ')' . "\n\nMore words.";
        $f['save_key'] = self::K1;
        $f['base_revision'] = $this->revision();
        $this->assertSame(422, $this->jpost(1, '/chapter/update/fx/1', $f)->status);
    }
}
