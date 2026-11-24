<?php
declare(strict_types=1);
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Tests\Support\TaxonomyCase;

/** /story/new is the chapter editor: Publish asks for the story's own answers, and one request
 *  creates the story and its first chapter together. */
#[Small]
final class NewStoryEditorTest extends TaxonomyCase
{
    private const JSON = ['accept' => 'application/json'];
    private const LISTINGS = ['/browse/recent', '/feed'];
    private int $rating;
    private int $general;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rating = (int) $this->row("SELECT id FROM ratings WHERE label='Teen'")['id'];
        $this->general = (int) $this->row("SELECT id FROM categories WHERE slug='general'")['id'];
        $this->db->query("UPDATE users SET role='validated_author' WHERE id=?", [$this->authorId]);
        \App\Features::init($this->db, []);
    }

    private function fields(array $over = []): array
    {
        return $over + [
            'chapter_title' => 'Chapter One', 'content' => 'Four fresh words here.', 'notes_before' => '', 'notes_after' => '',
            'title' => 'A Brand New Story', 'summary' => 'It begins.', 'rating_id' => (string) $this->rating, 'language' => 'en',
            'categories' => [(string) $this->general], 'notes' => 'Story notes', 'gift_to' => '',
        ];
    }

    private function xp(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        return new \DOMXPath($doc);
    }

    private function counts(): array
    {
        return [$this->n('SELECT COUNT(*) c FROM stories'), $this->n('SELECT COUNT(*) c FROM chapters')];
    }

    private function newest(): array
    {
        return $this->row('SELECT * FROM stories ORDER BY id DESC LIMIT 1');
    }

    private function advance(array $story, int $chapters = 1): void
    {
        self::assertSame($chapters, $this->n('SELECT COUNT(*) c FROM chapters WHERE story_id=?', [$story['id']]));
    }

    public function test_the_page_is_the_chapter_editor_in_new_story_mode(): void
    {
        $r = $this->client($this->authorId)->get('/story/new');
        self::assertSame(200, $r->status);
        $x = $this->xp($r->body);
        $form = '//form[@id="chapter-editor-form"]';
        self::assertSame('/story/create', $x->evaluate('string(' . $form . '/@action)'));
        self::assertSame('new-story', $x->evaluate('string(' . $form . '/@data-ed-mode)'));
        self::assertSame(1, $x->query($form . '//input[@name="chapter_title"]')->length);
        self::assertSame(1, $x->query($form . '//textarea[@name="content"]')->length);
        self::assertSame(0, $x->query('//form[@id="story-editor-form"]')->length);
        self::assertSame(1, $x->query($form . '//button[@data-ed-primary and normalize-space()="Publish Story"]')->length);
        self::assertSame(1, $x->query($form . '//button[@name="draft" and @value="1" and @formnovalidate]')->length);
        self::assertSame(0, $x->query($form . '//*[@data-ed-row="who"]')->length, 'a new story has no chapter-only password row');
        self::assertSame(0, $x->query($form . '//*[@id="ed-contents"]')->length);
    }

    public function test_every_old_story_field_is_still_on_the_page(): void
    {
        $x = $this->xp($this->client($this->authorId)->get('/story/new')->body);
        foreach (['title', 'summary', 'language', 'notes', 'gift_to', 'completed', 'restricted',
            'round_robin', 'is_original', 'access_password', 'access_clear'] as $name) {
            self::assertGreaterThan(0, $x->query('//form[@id="chapter-editor-form"]//*[@name="' . $name . '"]')->length, $name);
        }
        self::assertGreaterThan(0, $x->query('//input[@type="radio" and @name="rating_id" and @required]')->length);
        self::assertGreaterThan(0, $x->query('//input[@type="checkbox" and @name="categories[]"]')->length);
        self::assertGreaterThan(0, $x->query('//span[contains(@class,"ed-select")]/select[@name="language"]')->length);
        self::assertSame(1, $x->query('//input[@name="title" and @required and @maxlength="200"]')->length);
    }

    public function test_the_extra_fields_live_inside_the_more_options_details(): void
    {
        $x = $this->xp($this->client($this->authorId)->get('/story/new')->body);
        $more = '//details[contains(@class,"ed-moreopts")]';
        self::assertSame(1, $x->query($more)->length);
        foreach (['notes', 'gift_to', 'completed', 'restricted', 'round_robin', 'is_original', 'access_password'] as $name) {
            self::assertSame(1, $x->query($more . '//*[@name="' . $name . '"]')->length, $name);
        }
        foreach (['title', 'summary', 'rating_id', 'language'] as $name) {
            self::assertSame(0, $x->query($more . '//*[@name="' . $name . '"]')->length, $name . ' stays in its own row');
        }
    }

    public function test_publish_as_an_auto_author_creates_the_story_and_chapter_together(): void
    {
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields());
        self::assertSame(302, $r->status, $r->body);
        $story = $this->newest();
        self::assertSame('/story/edit/' . $story['slug'] . '?submitted=live', $r->headers['Location'] ?? $r->headers['location'] ?? '');
        self::assertSame('A Brand New Story', $story['title']);
        self::assertSame(1, (int) $story['validated']);
        self::assertSame(0, (int) $story['is_draft']);
        self::assertSame('It begins.', $story['summary']);
        self::assertSame('Story notes', $story['notes']);
        $ch = $this->row('SELECT * FROM chapters WHERE story_id=?', [$story['id']]);
        self::assertSame(1, (int) $ch['position']);
        self::assertSame('Chapter One', $ch['title']);
        self::assertSame(0, (int) $ch['is_draft']);
        self::assertSame(1, $this->n('SELECT COUNT(*) c FROM story_categories WHERE story_id=?', [$story['id']]));
        self::assertSame(200, $this->client()->get('/story/view/' . $story['slug'])->status);
    }

    public function test_publish_as_a_held_author_waits_in_the_queue(): void
    {
        $this->db->query("UPDATE users SET role='member' WHERE id=?", [$this->authorId]);
        $before = $this->n('SELECT COUNT(*) c FROM stories s WHERE ' . \App\Repositories\AuthoringRepository::WAITING_STORY);
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields());
        self::assertSame(302, $r->status, $r->body);
        $story = $this->newest();
        self::assertStringEndsWith('?submitted=held', $r->headers['Location'] ?? $r->headers['location'] ?? '');
        self::assertSame([0, 0], [(int) $story['validated'], (int) $story['is_draft']]);
        self::assertSame($before + 1, $this->n('SELECT COUNT(*) c FROM stories s WHERE ' . \App\Repositories\AuthoringRepository::WAITING_STORY));
        // A held author who schedules still waits for a moderator.
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['title' => 'Held Scheduled', 'publish_at' => '2031-01-01T09:00']));
        self::assertStringEndsWith('?submitted=held', $r->headers['Location'] ?? $r->headers['location'] ?? '');
    }

    public function test_save_as_draft_is_hidden_everywhere_and_out_of_the_queue(): void
    {
        $queue = $this->n('SELECT COUNT(*) c FROM stories s WHERE ' . \App\Repositories\AuthoringRepository::WAITING_STORY);
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1', 'title' => 'Secret Draft Story']));
        self::assertSame(302, $r->status, $r->body);
        $story = $this->newest();
        self::assertSame([0, 1], [(int) $story['validated'], (int) $story['is_draft']]);
        self::assertSame(1, (int) $this->row('SELECT is_draft FROM chapters WHERE story_id=?', [$story['id']])['is_draft']);
        self::assertSame('/chapter/edit/' . $story['slug'] . '/1?saved=draft', $r->headers['Location'] ?? $r->headers['location'] ?? '');
        self::assertSame($queue, $this->n('SELECT COUNT(*) c FROM stories s WHERE ' . \App\Repositories\AuthoringRepository::WAITING_STORY));
        $guest = $this->client();
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['title' => 'Control Live Story']));
        foreach (self::LISTINGS as $url) {
            self::assertStringContainsString('Control Live Story', $guest->get($url)->body, $url . ' must list a live story, or the hidden check proves nothing');
            self::assertStringNotContainsString('Secret Draft Story', $guest->get($url)->body, $url);
        }
        $this->db->query("UPDATE users SET role='moderator' WHERE id=?", [$this->memberId]);
        self::assertStringNotContainsString('Secret Draft Story', $this->client($this->memberId)->get('/queue')->body);
        self::assertSame(404, $guest->get('/story/view/' . $story['slug'])->status);
        self::assertSame(404, $guest->get('/story/read/' . $story['slug'] . '/1')->status);
        self::assertStringNotContainsString('Secret Draft Story', $guest->get('/search', ['q' => 'Secret'])->body);
    }

    public function test_publishing_a_new_story_with_a_missing_image_file_is_refused_but_a_draft_saves(): void
    {
        \App\Settings::put('chapter_images_on', '1');
        $src = '/uploads/' . bin2hex(random_bytes(8)) . '.png';
        $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, created_at) VALUES (?, ?, ?, ?)', [$this->authorId, $src, 'x', date('c')]);
        $content = "Words here.\n\n![A map]($src)";
        $before = $this->counts();
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['content' => $content]));
        self::assertSame(422, $r->status);
        self::assertStringContainsString($src, $r->body);
        self::assertSame($before, $this->counts());
        $d = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['content' => $content, 'draft' => '1']));
        self::assertSame(302, $d->status, $d->body);
    }

    public function test_a_draft_with_a_blank_story_title_borrows_a_name(): void
    {
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1', 'title' => '']));
        self::assertSame('Chapter One', $this->newest()['title']);
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1', 'title' => '', 'chapter_title' => '']));
        self::assertSame('Untitled Story', $this->newest()['title']);
    }

    public function test_a_draft_still_needs_a_rating_and_writes_nothing(): void
    {
        $before = $this->counts();
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1', 'rating_id' => '']));
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Choose a rating to save this draft.', $r->body);
        self::assertSame($before, $this->counts());
        $p = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['rating_id' => '']));
        self::assertSame(422, $p->status);
        self::assertStringContainsString('Choose a rating.', $p->body, 'publish keeps its own wording');
    }

    public function test_publish_banners_on_story_settings_use_the_title_case_wording(): void
    {
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields());
        $slug = $this->newest()['slug'];
        $c = $this->client($this->authorId);
        self::assertStringContainsString('Your Story Is Live', $c->get('/story/edit/' . $slug, ['submitted' => 'live'])->body);
        self::assertStringContainsString('Your Story Is Scheduled', $c->get('/story/edit/' . $slug, ['submitted' => 'scheduled'])->body);
        self::assertStringContainsString('Sent for Review', $c->get('/story/edit/' . $slug, ['submitted' => 'held'])->body);
    }

    public function test_each_refusal_is_a_422_that_writes_nothing_and_points_at_the_field(): void
    {
        $this->db->query("INSERT INTO categories(name,slug,locked) VALUES ('Private Picks','private-picks',1)");
        $locked = (string) $this->db->lastInsertId();
        $before = $this->counts();
        foreach ([
            [['title' => ''], 'story', 'ed-f-title'],
            [['rating_id' => ''], 'rating', 'ed-f-rating'],
            [['categories' => [$locked]], 'categories', 'ed-f-category'],
            [['content' => '   '], '', 'ed-f-content'],
            [['publish_at' => 'not a date'], 'when', 'ed-f-publish'],
        ] as [$over, $row, $field]) {
            $post = $this->fields($over + ['notes_before' => 'Keep me', 'summary' => 'Keep my summary']);
            $r = $this->client($this->authorId)->postWithToken('/story/create', $post);
            self::assertSame(422, $r->status, json_encode($over));
            $x = $this->xp($r->body);
            self::assertSame($row, $x->evaluate('string(//form[@id="chapter-editor-form"]/@data-ed-open)'), json_encode($over));
            self::assertSame($field, $x->evaluate('string(//form[@id="chapter-editor-form"]/@data-ed-focus)'), json_encode($over));
            self::assertStringContainsString('Keep my summary', $r->body);
            self::assertStringContainsString('Keep me', $r->body);
            self::assertSame($post['chapter_title'], $x->evaluate('string(//input[@name="chapter_title"]/@value)'));
            self::assertSame($before, $this->counts(), json_encode($over));
        }
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['title' => '']));
        self::assertStringContainsString('This Story Was Not Saved', $r->body);
        self::assertStringContainsString('href="#ed-f-title"', $r->body);
    }

    public function test_a_failure_after_the_story_insert_leaves_no_story_behind(): void
    {
        $before = $this->counts();
        $this->db->query('CREATE TRIGGER no_first_chapter BEFORE INSERT ON chapters BEGIN SELECT RAISE(ABORT, \'boom\'); END');
        try {
            $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields());
            self::assertGreaterThanOrEqual(400, $r->status);
        } finally {
            $this->db->query('DROP TRIGGER no_first_chapter');
        }
        self::assertSame($before, $this->counts());
    }

    public function test_a_json_save_key_replays_and_conflicts(): void
    {
        $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $client = $this->client($this->authorId);
        $first = $client->post('/story/create', $this->fields(['save_key' => $key, '_token' => $client->csrfToken()]), self::JSON);
        self::assertSame(200, $first->status, $first->body);
        $stories = $this->n('SELECT COUNT(*) c FROM stories');
        $again = $client->post('/story/create', $this->fields(['save_key' => $key, '_token' => $client->csrfToken()]), self::JSON);
        self::assertSame(200, $again->status);
        self::assertSame(json_decode($first->body, true)['redirect'], json_decode($again->body, true)['redirect']);
        self::assertSame($stories, $this->n('SELECT COUNT(*) c FROM stories'));
        $changed = $client->post('/story/create', $this->fields(['save_key' => $key, 'summary' => 'Different', '_token' => $client->csrfToken()]), self::JSON);
        self::assertSame(409, $changed->status);
        $bad = $client->post('/story/create', $this->fields(['title' => '', 'save_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '_token' => $client->csrfToken()]), self::JSON);
        self::assertSame(422, $bad->status);
        $body = json_decode($bad->body, true);
        self::assertSame(['story', 'ed-f-title'], [$body['row'], $body['field']]);
    }

    public function test_a_scheduled_story_stays_hidden_until_it_is_released(): void
    {
        $r = $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['title' => 'Scheduled Tale', 'publish_at' => '2031-01-01T09:00']));
        self::assertStringEndsWith('?submitted=scheduled', $r->headers['Location'] ?? $r->headers['location'] ?? '');
        $story = $this->newest();
        self::assertSame([0, 1], [(int) $story['validated'], (int) $story['is_draft']]);
        self::assertSame(404, $this->client()->get('/story/view/' . $story['slug'])->status);
        foreach (self::LISTINGS as $url) self::assertStringNotContainsString('Scheduled Tale', $this->client()->get($url)->body, $url);
        $this->db->query("UPDATE chapters SET publish_at='2000-01-01T00:00:00Z' WHERE story_id=?", [$story['id']]);
        (new \App\Repositories\AuthoringRepository($this->db))->releaseDue();
        $after = $this->row('SELECT validated, is_draft FROM stories WHERE id=?', [$story['id']]);
        self::assertSame([1, 0], [(int) $after['validated'], (int) $after['is_draft']]);
        self::assertSame(200, $this->client()->get('/story/view/' . $story['slug'])->status);
        foreach (self::LISTINGS as $url) self::assertStringContainsString('Scheduled Tale', $this->client()->get($url)->body, $url . ' lists the released story');
    }

    public function test_moderators_cannot_approve_or_remove_a_draft_story(): void
    {
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1']));
        $story = $this->newest();
        $repo = new \App\Repositories\AuthoringRepository($this->db);
        self::assertNull($repo->approveStory((int) $story['id']));
        self::assertNull($repo->removeStory((int) $story['id']));
        self::assertSame(1, $this->n('SELECT COUNT(*) c FROM stories WHERE id=?', [$story['id']]));
    }

    public function test_a_post_without_a_chapter_body_still_makes_a_story_only(): void
    {
        $f = $this->fields();
        unset($f['content'], $f['chapter_title']);
        $r = $this->client($this->authorId)->postWithToken('/story/create', $f);
        self::assertSame(302, $r->status, $r->body);
        self::assertSame(0, $this->n('SELECT COUNT(*) c FROM chapters WHERE story_id=?', [$this->newest()['id']]));
    }

    public function test_a_story_draft_reopens_in_the_editor_and_publishes_in_one_step(): void
    {
        $this->client($this->authorId)->postWithToken('/story/create', $this->fields(['draft' => '1', 'title' => 'Draft Then Live']));
        $story = $this->newest();
        $client = $this->client($this->authorId);
        $page = $client->get('/chapter/edit/' . $story['slug'] . '/1');
        self::assertSame(200, $page->status);
        $x = $this->xp($page->body);
        self::assertSame('draft-story', $x->evaluate('string(//form[@id="chapter-editor-form"]/@data-ed-mode)'));
        self::assertSame('Draft Then Live', $x->evaluate('string(//input[@name="story_title"]/@value)'));
        self::assertSame('Chapter One', $x->evaluate('string(//input[@name="title"]/@value)'));
        self::assertSame(0, $x->query('//*[@data-ed-row="who"]')->length);
        $base = $x->evaluate('string(//input[@name="base_revision"]/@value)');
        $r = $client->postWithToken('/chapter/update/' . $story['slug'] . '/1', [
            'title' => 'Chapter One', 'content' => 'Four fresh words here.', 'notes_before' => '', 'notes_after' => '', 'base_revision' => $base,
            'story_title' => 'Renamed Live Story', 'summary' => 'It begins.', 'rating_id' => (string) $this->rating, 'language' => 'en',
            'categories' => [(string) $this->general],
        ]);
        self::assertSame(302, $r->status, $r->body);
        $after = $this->row('SELECT * FROM stories WHERE id=?', [$story['id']]);
        self::assertSame('Renamed Live Story', $after['title']);
        self::assertSame([1, 0], [(int) $after['validated'], (int) $after['is_draft']]);
        self::assertSame(0, (int) $this->row('SELECT is_draft FROM chapters WHERE story_id=?', [$story['id']])['is_draft']);
    }

    public function test_the_old_new_story_script_is_gone(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 4) . '/public/assets/story-draft.js');
        self::assertStringNotContainsString('story-draft', (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/app.js'));
    }
}
