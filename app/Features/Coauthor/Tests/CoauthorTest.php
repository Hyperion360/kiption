<?php // app/Features/Coauthor/Tests/CoauthorTest.php
namespace App\Features\Coauthor\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class CoauthorTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $otherUserId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-coa-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-coa-mail-') . '.log';
        touch($this->mailLog);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        // a full member to attach as coauthor (approved, verified, unlocked)
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('ow@e.test', ?, 'otherwriter', ?, ?, 'otherwriter')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $this->otherUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-coa-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ] + $extra);
        $client = new TestClient($app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function authorId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    private function otherId(): int
    {
        return $this->otherUserId;
    }

    public function test_owner_adds_coauthor_who_gains_authoring_rights(): void
    {
        $owner = $this->client($this->authorId());
        $res = $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $res->status, $res->body);
        // byline shows both
        $body = $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringContainsString('href="/user/view/otherwriter"', $body);
        // the coauthor can now open the chapter form (previously RuntimeException -> 404)
        $co = $this->client($this->otherId());
        $this->assertSame(200, $co->get('/chapter/new/the-rabbit-hole')->status);
        // and can edit the story form
        $this->assertSame(200, $co->get('/story/edit/the-rabbit-hole')->status);
    }

    public function test_add_validates_penname_and_membership(): void
    {
        $owner = $this->client($this->authorId());
        $this->assertSame(422, $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'ghost'])->status);
        $this->assertSame(422, $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'Demo Author'])->status); // self
        // non-owner cannot add
        $co = $this->client($this->otherId());
        $this->assertSame(404, $co->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'betafriend'])->status);
    }

    public function test_failed_coauthor_add_retains_the_complete_story_form_without_warnings(): void
    {
        $storyId = (int) $this->db->one("SELECT id FROM stories WHERE slug='the-rabbit-hole'")['id'];
        $type = (int) $this->db->one("SELECT id FROM tag_types WHERE name='genre'")['id'];
        $this->db->query('INSERT INTO tags(tag_type_id,name) VALUES (?,?)', [$type, 'Saved Genre']);
        $tag = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_tags(story_id,tag_id) VALUES (?,?)', [$storyId, $tag]);
        $this->db->query("INSERT INTO characters(name,slug) VALUES ('Saved Character','saved-character')");
        $character = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO story_characters(story_id,character_id) VALUES (?,?)', [$storyId, $character]);
        $this->db->query("INSERT INTO tag_suggestions(story_id,tag_type_id,name,suggested_by,created_at) VALUES (?,?,'Pending Genre',?,?)",
            [$storyId, $type, $this->authorId(), date('c')]);
        $this->db->query("INSERT INTO settings(key,value) VALUES ('tag_policy','suggest') ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        \App\Features::init($this->db, []);
        $this->db->query("UPDATE stories SET title='Saved Metadata',summary=?,notes=?,language='fr',completed=1,
            is_restricted=1,round_robin=1,is_original=1,gift_to='A Reader' WHERE id=?",
            ["A summary\nwith <escaped> text.", "**Saved notes**\nSecond line.", $storyId]);
        $owner = $this->client($this->authorId());
        foreach (['canonical_url', 'crosspost_url'] as $syndication) {
            $this->db->query('UPDATE stories SET canonical_url=?,crosspost_url=? WHERE id=?',
                [$syndication === 'canonical_url' ? 'https://example.test/original' : null,
                 $syndication === 'crosspost_url' ? 'https://example.test/copy' : null, $storyId]);
            $before = $this->db->one('SELECT title,summary,notes,rating_id,language,completed,is_restricted,round_robin,is_original,gift_to,canonical_url,crosspost_url FROM stories WHERE id=?', [$storyId]);
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            try {
                $response = $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'missing-coauthor']);
            } finally {
                restore_error_handler();
            }
            $this->assertSame(422, $response->status);
            $this->assertSame([], $warnings, 'the shared form has a complete render context');
            $doc = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try { $doc->loadHTML($response->body); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            $x = new \DOMXPath($doc);
            $form = $x->query('//*[@id="story-editor-form"]')->item(0);
            $this->assertNotNull($form);
            $this->assertSame('/story/update/the-rabbit-hole', $form->getAttribute('action'));
            $labels = json_decode($form->getAttribute('data-writer-labels'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['unsaved' => \App\Lang::t('writer.unsaved'), 'saved' => \App\Lang::t('writer.saved'), 'saving' => \App\Lang::t('writer.saving')], $labels);
            $this->assertSame('1', $form->getAttribute('data-writer-rejected'));
            $this->assertSame('0', $form->getAttribute('data-writer-saved'));
            $fields = $this->storyFormFields($x);
            foreach (['title', 'summary', 'notes', 'rating_id', 'language', 'gift_to', 'canonical_url', 'crosspost_url'] as $name) {
                $this->assertSame((string) ($before[$name] ?? ''), $fields[$name], $name);
            }
            foreach (['completed', 'restricted', 'round_robin', 'is_original'] as $name) $this->assertSame('1', $fields[$name], $name);
            $this->assertSame('', $fields['access_password']);
            $this->assertArrayNotHasKey('access_clear', $fields);
            $this->assertArrayNotHasKey('withdraw', $fields);
            $this->assertSame('', $fields['suggest'][$type]);
            $this->assertContains((string) $tag, $fields['tags']);
            $this->assertSame([(string) $character], $fields['characters']);
            $this->assertNotEmpty($fields['categories']);
            $this->assertStringNotContainsString('Enter the story password again before saving.', $response->body);
            $this->assertSame(0, $x->query('//select[@name="rating_id"]/option[@disabled and @selected]')->length);
            $saved = $owner->postWithToken($form->getAttribute('action'), $fields);
            $this->assertSame(302, $saved->status, $saved->body);
            $this->assertSame($before, $this->db->one('SELECT title,summary,notes,rating_id,language,completed,is_restricted,round_robin,is_original,gift_to,canonical_url,crosspost_url FROM stories WHERE id=?', [$storyId]));
        }
    }

    /** Serialize the story form as a browser would, excluding separate coauthor forms. */
    private function storyFormFields(\DOMXPath $x): array
    {
        $pairs = [];
        foreach ($x->query('//*[@id="story-editor-form"]//*[@name]') as $node) {
            if ($node->hasAttribute('disabled')) continue;
            if ($node->getAttribute('type') === 'checkbox' && !$node->hasAttribute('checked')) continue;
            if ($node->nodeName === 'select') {
                $option = $x->query('option[@selected]', $node)->item(0) ?? $x->query('option', $node)->item(0);
                $value = $option?->getAttribute('value') ?? '';
            } elseif ($node->nodeName === 'textarea') {
                // Legacy libxml retains the first LF consumed by HTML browsers.
                $value = str_starts_with($node->textContent, "\n") ? substr($node->textContent, 1) : $node->textContent;
            } else {
                $value = $node->getAttribute('value');
            }
            $pairs[] = rawurlencode($node->getAttribute('name')) . '=' . rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $fields);
        return $fields;
    }

    public function test_coauthor_cannot_add_other_coauthors(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        // coauthors gain editing rights, NOT coauthor-management rights; the raw
        // POST must fail exactly like the hidden form section (defense in depth)
        $co = $this->client($this->otherId());
        $this->assertSame(404, $co->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'betafriend'])->status);
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) c FROM coauthors')['c'], 'guarded intake must store nothing');
    }

    public function test_coauthor_may_leave_and_owner_may_remove(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $co = $this->client($this->otherId());
        $this->assertSame(302, $co->postWithToken('/coauthor/leave/the-rabbit-hole')->status);
        $this->assertSame(404, $co->get('/chapter/new/the-rabbit-hole')->status);
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $owner->postWithToken('/coauthor/remove/the-rabbit-hole/' . $this->otherId())->status);
    }

    public function test_review_and_kudos_notify_author_and_coauthor(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $fan = $this->client($this->memberId());
        $fan->postWithToken('/kudos/add/the-rabbit-hole');
        $fan->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Both of you, bravo.', 'rating' => '', 'guest_name' => '']);
        $ownerInbox = $owner->get('/notifications')->body;
        $coInbox = $this->client($this->otherId())->get('/notifications')->body;
        $this->assertStringContainsString('left kudos', $ownerInbox);
        $this->assertStringContainsString('left kudos', $coInbox);
        $this->assertStringContainsString('reviewed', $coInbox);
    }

    public function test_added_coauthor_gets_email_and_notification(): void
    {
        $owner = $this->client($this->authorId());
        $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $inbox = $this->client($this->otherId())->get('/notifications')->body;
        $this->assertStringContainsString('coauthor', $inbox);
        // unread is announced, not only drawn as an accent square (qa-full /a11y, WCAG 1.4.1)
        $this->assertStringContainsString('<span class="visually-hidden">Unread: </span>', $inbox);
        $mail = file_get_contents($this->mailLog);
        $this->assertStringContainsString('coauthor', $mail);
        $this->assertStringContainsString('The Rabbit Hole', $mail);
    }

    /** Task B6: a coauthor who is never emailed does not know they were
     *  added, so the refusal writes one null-actor row (the story title in
     *  the detail, never the address). The add itself still succeeds. */
    public function test_a_refused_coauthor_add_writes_one_activity_row(): void
    {
        $owner = $this->client($this->authorId(), ['mail_daily_cap' => 10]); // usable 0: the mail is refused
        $res = $owner->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'otherwriter']);
        $this->assertSame(302, $res->status, 'the add still succeeds');
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) c FROM coauthors WHERE user_id = ?", [$this->otherUserId])['c']);
        $rows = $this->db->all("SELECT actor_id, action, detail FROM activity_log WHERE action = 'coauthor.refused'");
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['actor_id']);
        $this->assertStringContainsString('The Rabbit Hole', $rows[0]['detail'], 'the story title is named');
        $this->assertStringNotContainsString('ow@e.test', $rows[0]['detail'], 'the address never rides the log');
        $this->assertSame('', (string) file_get_contents($this->mailLog));
    }
}
