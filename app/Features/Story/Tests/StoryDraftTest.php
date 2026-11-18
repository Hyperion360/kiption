<?php
declare(strict_types=1);
namespace App\Features\Story\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Tests\Support\TaxonomyCase;

#[Small]
final class StoryDraftTest extends TaxonomyCase
{
    private int $general;
    private int $rating;
    private int $tag;
    private int $type;
    private int $character;
    private int $pending;
    private int $story;

    protected function setUp(): void
    {
        parent::setUp();
        $this->general = (int) $this->row("SELECT id FROM categories WHERE slug='general'")['id'];
        $this->rating = (int) $this->row("SELECT id FROM ratings WHERE label='Teen'")['id'];
        $this->story = (int) $this->row("SELECT id FROM stories WHERE slug='the-rabbit-hole'")['id'];
        $this->type = (int) $this->row("SELECT id FROM tag_types WHERE name='genre'")['id'];
        $this->db->query('INSERT INTO tags(tag_type_id,name) VALUES (?, ?)', [$this->type, 'Draft Genre']);
        $this->tag = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO characters(name,slug) VALUES ('Draft Character','draft-character')");
        $this->character = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO tag_suggestions(story_id,tag_type_id,name,suggested_by,status,created_at) VALUES (?,?,'Pending Genre',?,'pending',?)",
            [$this->story, $this->type, $this->authorId, date('c')]);
        $this->pending = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO settings(key,value) VALUES ('tag_policy','suggest') ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        \App\Features::init($this->db, []);
    }

    private function fields(): array
    {
        return [
            'title' => '  A Different Title  ', 'summary' => "\n\n  First line\n<script>draft</script>  ",
            'notes' => "\n  **Keep these notes**\nSecond line  ", 'rating_id' => (string) $this->rating,
            'language' => 'invalid language', 'gift_to' => '  A Reader  ',
            'completed' => '1', 'restricted' => '1', 'round_robin' => '1', 'is_original' => '1',
            'categories' => [(string) $this->general], 'tags' => [(string) $this->tag],
            'characters_shown' => '1', 'characters' => [(string) $this->character],
            'suggest' => [$this->type => '  Unpublished Genre, Another Genre  '],
            'withdraw' => [(string) $this->pending], 'access_password' => 'discard-this-secret', 'access_clear' => '1',
            'canonical_url' => '  https://example.test/original  ', 'crosspost_url' => '',
        ];
    }

    private function form(string $body): \DOMXPath
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $body);
        return new \DOMXPath($doc);
    }

    private function value(\DOMXPath $x, string $name): string
    {
        $node = $x->query('//*[@id="story-editor-form"]//*[@name="' . $name . '"]')->item(0);
        self::assertNotNull($node, $name);
        if ($node->nodeName === 'select') {
            $picked = $x->query('.//option[@selected]', $node)->item(0);
            return $picked === null ? '' : $picked->getAttribute('value');
        }
        if ($node->nodeName !== 'textarea') return $node->getAttribute('value');
        // libxml's legacy parser retains the initial LF that HTML browsers consume.
        return str_starts_with($node->textContent, "\n") ? substr($node->textContent, 1) : $node->textContent;
    }

    private function selected(\DOMXPath $x, string $name): array
    {
        $values = [];
        foreach ($x->query('//*[@id="story-editor-form"]//input[@name="' . $name . '" and @checked]') as $node) {
            $values[] = $node->getAttribute('value');
        }
        return $values;
    }

    public function test_rejected_update_retains_fields_and_resubmits_to_the_same_story(): void
    {
        $this->db->query('UPDATE stories SET access_hash=? WHERE id=?', ['stored-password-hash-canary', $this->story]);
        $before = $this->row('SELECT * FROM stories WHERE id=?', [$this->story]);
        $count = $this->n('SELECT COUNT(*) c FROM stories');
        $fields = $this->fields();
        $fields['rating_id'] = '999999';
        $client = $this->client($this->authorId);
        $response = $client->postWithToken('/story/update/the-rabbit-hole', $fields);
        self::assertSame(422, $response->status);
        $x = $this->form($response->body);
        self::assertSame('/story/update/the-rabbit-hole', $x->evaluate('string(//*[@id="story-editor-form"]/@action)'));
        self::assertSame('Save', trim($x->evaluate('string(//*[@id="story-editor-form"]//button[@type="submit"])')));
        self::assertSame('1', $x->evaluate('string(//*[@id="story-editor-form"]/@data-writer-rejected)'));
        self::assertSame('0', $x->evaluate('string(//*[@id="story-editor-form"]/@data-writer-saved)'));
        foreach (['title', 'summary', 'notes', 'language', 'gift_to', 'canonical_url', 'crosspost_url'] as $name) {
            self::assertSame($fields[$name], $this->value($x, $name), $name);
        }
        foreach (['categories[]' => $this->general, 'tags[]' => $this->tag, 'characters[]' => $this->character, 'withdraw[]' => $this->pending] as $name => $id) {
            self::assertSame([(string) $id], $this->selected($x, $name), $name);
        }
        foreach (['completed', 'restricted', 'round_robin', 'is_original', 'access_clear'] as $name) {
            self::assertSame(['1'], $this->selected($x, $name), $name);
        }
        self::assertSame($fields['suggest'][$this->type], $this->value($x, 'suggest[' . $this->type . ']'));
        self::assertSame('', $this->value($x, 'access_password'));
        self::assertStringContainsString('Enter the story password again before saving.', $response->body);
        self::assertStringNotContainsString($fields['access_password'], $response->body);
        self::assertStringNotContainsString('stored-password-hash-canary', $response->body);
        self::assertSame(0, $x->query('//script[contains(text(),"draft")]')->length);
        self::assertSame('', $x->evaluate('string(//select[@name="rating_id"]/option[@selected]/@value)'));
        self::assertSame(1, $x->query('//select[@name="rating_id" and @required]')->length);
        self::assertSame($before, $this->row('SELECT * FROM stories WHERE id=?', [$this->story]));
        self::assertSame('pending', $this->row('SELECT status FROM tag_suggestions WHERE id=?', [$this->pending])['status']);
        $fields['rating_id'] = (string) $this->rating;
        $fields['language'] = 'en';
        unset($fields['access_password']);
        $saved = $client->postWithToken('/story/update/the-rabbit-hole', $fields);
        self::assertSame(302, $saved->status, $saved->body);
        self::assertSame($count, $this->n('SELECT COUNT(*) c FROM stories'));
        self::assertSame('A Different Title', $this->row('SELECT title FROM stories WHERE id=?', [$this->story])['title']);
    }

    public function test_each_validation_failure_keeps_its_form_identity_and_draft(): void
    {
        $this->db->query("INSERT INTO categories(name,slug,locked) VALUES ('Private Picks','private-picks',1)");
        $locked = (string) $this->db->lastInsertId();
        foreach (['/story/create', '/story/update/the-rabbit-hole'] as $action) {
            $invalid = [['title' => ''], ['rating_id' => '999999'], ['categories' => [$locked]]];
            if (str_contains($action, '/update/')) $invalid[] = ['canonical_url' => '  javascript:bad()  '];
            foreach ($invalid as $override) {
                $fields = array_replace($this->fields(), $override);
                $response = $this->client($this->authorId)->postWithToken($action, $fields);
                self::assertSame(422, $response->status, json_encode($override));
                $x = $this->form($response->body);
                self::assertSame($action, $x->evaluate('string(//*[@id="story-editor-form"]/@action)'));
                self::assertSame($fields['title'], $this->value($x, 'title'));
                self::assertSame($fields['notes'], $this->value($x, 'notes'));
                self::assertSame(0, $x->query('//input[@name="categories[]" and @value="' . $locked . '"]')->length);
                if ($action === '/story/create') {
                    self::assertSame('Create', trim($x->evaluate('string(//*[@id="story-editor-form"]//button[@type="submit"])')));
                    self::assertSame(0, $x->query('//form[starts-with(@action,"/story/cover/")]')->length);
                } else {
                    self::assertSame($fields['canonical_url'], $this->value($x, 'canonical_url'));
                }
            }
        }
    }

    public function test_invalid_missing_deleted_and_other_author_updates_stay_private(): void
    {
        foreach (['missing-story', 'the-rabbit-hole'] as $slug) {
            $response = $this->client($this->memberId)->postWithToken('/story/update/' . $slug, ['rating_id' => '999999', 'title' => 'Draft']);
            self::assertSame(404, $response->status);
            self::assertStringNotContainsString('Pending Genre', $response->body);
            self::assertStringNotContainsString('story-editor-form', $response->body);
        }
        $this->db->query('UPDATE stories SET deleted_at=? WHERE id=?', [date('c'), $this->story]);
        self::assertSame(404, $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', ['title' => ''])->status);
    }

    public function test_old_character_picker_sentinel_survives_rejection_and_resubmission(): void
    {
        $this->db->query('INSERT INTO story_characters(story_id,character_id) VALUES (?,?)', [$this->story, $this->character]);
        $fields = $this->fields();
        unset($fields['characters_shown'], $fields['characters'], $fields['access_password']);
        $fields['rating_id'] = '999999';
        $response = $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', $fields);
        $x = $this->form($response->body);
        self::assertSame([(string) $this->character], $this->selected($x, 'characters[]'));
        self::assertStringNotContainsString('Enter the story password again before saving.', $response->body);
        $fields['characters_shown'] = '1';
        $fields['characters'] = $this->selected($x, 'characters[]');
        $fields['rating_id'] = (string) $this->rating;
        self::assertSame(302, $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', $fields)->status);
        self::assertSame(1, $this->n('SELECT COUNT(*) c FROM story_characters WHERE story_id=? AND character_id=?', [$this->story, $this->character]));
        $fields['rating_id'] = '999999';
        $fields['characters'] = [];
        $response = $this->client($this->authorId)->postWithToken('/story/update/a-different-title', $fields);
        self::assertSame([], $this->selected($this->form($response->body), 'characters[]'));
    }

    public function test_admin_and_coauthor_rejections_keep_edit_access_and_saved_locked_choices(): void
    {
        $this->db->query('INSERT INTO coauthors(story_id,user_id) VALUES (?,?)', [$this->story, $this->memberId]);
        $this->db->query("INSERT INTO categories(name,slug,locked) VALUES ('Saved Staff Picks','saved-staff-picks',1)");
        $locked = (string) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_categories(story_id,category_id) VALUES (?,?)', [$this->story, $locked]);
        foreach ([$this->adminId, $this->memberId] as $user) {
            $fields = ['rating_id' => '999999', 'categories' => [$locked]] + $this->fields();
            $response = $this->client($user)->postWithToken('/story/update/the-rabbit-hole', $fields);
            self::assertSame(422, $response->status);
            $x = $this->form($response->body);
            self::assertSame('/story/update/the-rabbit-hole', $x->evaluate('string(//*[@id="story-editor-form"]/@action)'));
            self::assertSame([$locked], $this->selected($x, 'categories[]'));
        }
    }

    public function test_nested_text_is_not_echoed_or_cast_when_a_draft_is_rejected(): void
    {
        $fields = $this->fields();
        foreach (['title', 'summary', 'notes', 'language', 'gift_to', 'canonical_url', 'crosspost_url', 'access_password', 'rating_id'] as $name) {
            $fields[$name] = ['not-a-text-value'];
        }
        $fields['suggest'] = [$this->type => ['nested-secret']];
        $response = $this->client($this->authorId)->postWithToken('/story/update/the-rabbit-hole', $fields);
        self::assertSame(422, $response->status);
        self::assertStringNotContainsString('not-a-text-value', $response->body);
        self::assertStringNotContainsString('nested-secret', $response->body);
        self::assertSame('', $this->value($this->form($response->body), 'title'));
    }
}
