<?php // app/Features/Tagqueue/Tests/TagqueueTest.php
namespace App\Features\Tagqueue\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Tests\Support\TaxonomyCase;

/** The admin queue of waiting tag suggestions at /tagqueue (Task 4.4). */
#[Medium]
final class TagqueueTest extends TaxonomyCase
{
    private function typeId(string $name): int
    {
        return (int) $this->row('SELECT id FROM tag_types WHERE name = ?', [$name])['id'];
    }

    private function makeStory(string $slug): int
    {
        $this->db->query("INSERT INTO stories (title, slug, author_id, rating_id, validated) VALUES (?, ?, ?, 1, 1)", [ucwords(str_replace('-', ' ', $slug)), $slug, $this->authorId]);
        return (int) $this->db->lastInsertId();
    }

    private function suggest(int $storyId, int $by, string $name): void
    {
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->typeId('genre'), $name, $by, $storyId, 'pending', date('c')]);
    }

    public function test_admin_queue_lists_a_name_once_with_its_story_count(): void
    {
        foreach (['queue-one', 'queue-two', 'queue-three'] as $slug) {
            $this->suggest($this->makeStory($slug), $this->memberId, 'Waiting Marker');
        }
        $this->suggest($this->makeStory('queue-four'), $this->memberId, 'Lone Marker');
        $body = $this->admin()->get('/tagqueue')->body;
        $this->assertSame(1, substr_count($body, '<h2 class="section-label">Waiting Marker'), 'the three stories fold into one queue row');
        $this->assertSame(1, substr_count($body, '<h2 class="section-label">Lone Marker'), 'each distinct name is its own row');
        $this->assertStringContainsString('Queue One', $body, 'the story titles ride the row');
        $this->assertStringContainsString('3', $body, 'the count shows');
        // no scripts on the admin page
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/', $body, 'no inline script');
    }

    public function test_every_suggestion_gets_its_own_types_live_merge_choices(): void
    {
        $story = $this->makeStory('merge-choice-groups');
        $this->pendingRow($story, $this->memberId, 'Alpha Proposal');
        $this->pendingRow($story, $this->memberId, 'Zulu Proposal');
        $this->pendingRow($story, $this->memberId, 'Content Proposal', 'content');
        $fantasy = (int) $this->row("SELECT id FROM tags WHERE name = 'Fantasy'")['id'];
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES (?, ?, ?)',
            [$this->typeId('genre'), 'Retired Fantasy', $fantasy]);

        $response = $this->admin()->get('/tagqueue');
        $this->assertSame(200, $response->status);
        $doc = new \DOMDocument();
        @$doc->loadHTML($response->body);
        $xpath = new \DOMXPath($doc);
        $choices = [];
        foreach ($xpath->query('//form[@action="/tagqueue/merge"]') as $form) {
            $name = $xpath->evaluate('string(.//input[@name="name"]/@value)', $form);
            foreach ($xpath->query('.//select[@name="target_id"]/option[@value!=""]', $form) as $option) {
                $choices[$name][] = $option->textContent;
            }
        }
        $this->assertSame(['Adventure', 'Fantasy', 'Found family'], $choices['Alpha Proposal'] ?? []);
        $this->assertSame($choices['Alpha Proposal'], $choices['Zulu Proposal'] ?? []);
        $this->assertSame(['Coming of age', 'Maritime'], $choices['Content Proposal'] ?? []);
    }

    /** Titles ride the queue row as one GROUP_CONCAT string split on a
     *  separator. A title carrying the separator itself must come back as
     *  one title, not two. */
    public function test_a_pipe_in_a_story_title_stays_one_title(): void
    {
        $story = $this->makeStory('queue-pipe');
        $this->db->query('UPDATE stories SET title = ? WHERE id = ?', ['Alpha|Beta', $story]);
        $this->suggest($story, $this->memberId, 'Pipe Marker');
        $body = $this->admin()->get('/tagqueue')->body;
        $this->assertStringContainsString('Suggested On: Alpha|Beta', $body, 'the whole title shows');
        $this->assertStringNotContainsString('Suggested On: Alpha, Beta', $body, 'no split into two phantom titles');
    }

    public function test_queue_gates_and_noindex(): void
    {
        $res = $this->admin()->get('/tagqueue');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('noindex', $res->body, 'the meta robots rides the admin page');
        // the #[Auth] shape: a guest redirects to sign-in, a signed-in
        // non-admin is refused (the TagsController gate shape)
        $this->assertSame(302, $this->client()->get('/tagqueue')->status);
        $this->assertSame(403, $this->client($this->memberId)->get('/tagqueue')->status, 'members are denied');
    }

    public function test_queue_empty_state_and_nav_link(): void
    {
        $body = $this->admin()->get('/tagqueue')->body;
        $this->assertStringContainsString('No suggestions are waiting.', $body);
        // the sidebar nav lists the screen (group stories, no feature flag)
        $nav = $this->admin()->get('/manage')->body;
        $this->assertStringContainsString('/tagqueue', $nav);
    }

    public function test_queue_is_one_query(): void
    {
        $this->suggest($this->makeStory('queue-budget'), $this->memberId, 'Budget Marker');
        $app = $this->application();
        $client = (new \Kip\Testing\TestClient($app))->actingAs($this->adminId);
        $queries = 0;
        \App\Tests\Support\QueryTap::on($app->container->make(\Kip\Database::class), function (string $sql) use (&$queries): void {
            if ($sql === 'SELECT password_hash FROM users WHERE id = ?' || $sql === \App\SessionGuard::SQL) return; // session validation, excluded by rule
            $queries++;
        });
        $res = $client->get('/tagqueue');
        \App\Tests\Support\QueryTap::off($app->container->make(\Kip\Database::class));
        $this->assertSame(200, $res->status);
        $this->assertLessThanOrEqual(1, $queries, "the queue ran {$queries} queries, budget is 1");
    }

    private function application(): \Kip\App
    {
        return new \Kip\App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->path . '.mail', 'from' => 'noreply@localhost'],
            'uploads' => [sys_get_temp_dir() . '/kiption-tax-upl'],
            'static_cache' => ['dir' => $this->cacheDir],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    // ---- Task 4.5: approve, merge into existing, reject ----

    private function pendingRow(int $storyId, int $by, string $name, string $type = 'genre'): int
    {
        $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$this->typeId($type), $name, $by, $storyId, 'pending', date('c')]);
        return (int) $this->db->lastInsertId();
    }

    public function test_approve_creates_the_tag_attaches_every_story_and_clears_the_rows(): void
    {
        $ids = [];
        foreach (['approve-a', 'approve-b', 'approve-c'] as $slug) {
            $ids[] = $this->pendingRow($this->makeStory($slug), $this->memberId, 'Approved Marker');
        }
        $res = $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Approved Marker']);
        $this->assertSame(302, $res->status, $res->body);
        $tag = $this->row("SELECT id FROM tags WHERE name = 'Approved Marker'");
        $this->assertNotNull($tag, 'the live tag exists');
        $this->assertSame(3, $this->n('SELECT COUNT(*) c FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE t.name = ?', ['Approved Marker']));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions'));
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM activity_log WHERE action LIKE 'tag_suggestion.%'"), 'the action is logged');
    }

    public function test_approve_of_a_name_that_now_exists_just_attaches_it(): void
    {
        // race: another admin created the tag between list and approve
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$this->typeId('genre'), 'Race Marker']);
        $tagId = (int) $this->row("SELECT id FROM tags WHERE name = 'Race Marker'")['id'];
        $story = $this->makeStory('approve-race');
        $this->pendingRow($story, $this->memberId, 'Race Marker');
        $res = $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Race Marker']);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Race Marker'"), 'no duplicate tag row');
        $this->assertSame($tagId, (int) $this->row("SELECT tag_id FROM story_tags WHERE story_id = ?", [$story])['tag_id']);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions'));
    }

    public function test_approve_reuses_a_case_variant_created_after_suggestion(): void
    {
        $story = $this->makeStory('approve-case-race');
        $this->pendingRow($story, $this->memberId, 'race marker');
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$this->typeId('genre'), 'Race Marker']);
        $existing = (int) $this->row("SELECT id FROM tags WHERE name = 'Race Marker'")['id'];
        $res = $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->typeId('genre'), 'name' => 'race marker']);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE', [$this->typeId('genre'), 'race marker']));
        $this->assertSame($existing, (int) $this->row('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story])['tag_id']);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions WHERE story_id = ?', [$story]));
    }

    public function test_merge_refuses_a_case_variant_owned_by_another_target(): void
    {
        $story = $this->makeStory('merge-case-conflict');
        $this->pendingRow($story, $this->memberId, 'race marker');
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$this->typeId('genre'), 'Race Marker']);
        $fantasy = (int) $this->row("SELECT id FROM tags WHERE name = 'Fantasy'")['id'];
        $res = $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $this->typeId('genre'), 'name' => 'race marker', 'target_id' => (string) $fantasy]);
        $this->assertSame(422, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE', [$this->typeId('genre'), 'race marker']));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tag_suggestions WHERE story_id = ?', [$story]));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story]));
    }

    public function test_merge_reuses_a_case_variant_when_it_is_the_chosen_target(): void
    {
        $story = $this->makeStory('merge-case-same-target');
        $this->pendingRow($story, $this->memberId, 'race marker');
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$this->typeId('genre'), 'Race Marker']);
        $target = (int) $this->row("SELECT id FROM tags WHERE name = 'Race Marker'")['id'];
        $res = $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $this->typeId('genre'), 'name' => 'race marker', 'target_id' => (string) $target]);
        $this->assertSame(302, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tags WHERE tag_type_id = ? AND name = ? COLLATE NOCASE', [$this->typeId('genre'), 'race marker']));
        $this->assertSame($target, (int) $this->row('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story])['tag_id']);
    }

    public function test_approve_refuses_a_name_now_used_by_another_type(): void
    {
        $story = $this->makeStory('approve-cross-type-conflict');
        $this->pendingRow($story, $this->memberId, 'Maritime');
        $res = $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Maritime']);
        $this->assertSame(422, $res->status);
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tags WHERE name = ? COLLATE NOCASE', ['Maritime']));
        $this->assertSame(1, $this->n('SELECT COUNT(*) c FROM tag_suggestions WHERE story_id = ?', [$story]));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story]));
    }

    public function test_merge_attaches_the_chosen_tag_and_records_a_retired_synonym(): void
    {
        $target = (int) $this->row("SELECT id FROM tags WHERE name = 'Fantasy'")['id'];
        $story = $this->makeStory('merge-ride');
        $this->pendingRow($story, $this->memberId, 'High Fantasy');
        $res = $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $this->typeId('genre'), 'name' => 'High Fantasy', 'target_id' => (string) $target]);
        $this->assertSame(302, $res->status);
        $syn = $this->row("SELECT id, canonical_id FROM tags WHERE name = 'High Fantasy'");
        $this->assertNotNull($syn, 'the name is recorded as a retired synonym');
        $this->assertSame($target, (int) $syn['canonical_id']);
        $this->assertSame($target, (int) $this->row('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story])['tag_id']);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM tag_suggestions'));
        // the next submit of that name resolves straight to the canonical
        $repo = new \App\Repositories\TagSuggestionRepository($this->db);
        $out = $repo->submit($story, $this->memberId, [$this->typeId('genre') => 'high fantasy'], 'suggest');
        $this->assertSame(['Fantasy'], $out['attached'], 'the retired name resolves to the canonical');
        // Wrangling's unmerge clears the retirement without error (flag is on by inventory)
        $res = $this->admin()->postWithToken('/wrangling/unmerge/' . $syn['id']);
        $this->assertSame(302, $res->status);
        $canon = $this->row('SELECT canonical_id FROM tags WHERE id = ?', [(int) $syn['id']]);
        $this->assertNull($canon['canonical_id'], 'unmerge cleared the retirement');
    }

    public function test_merge_refuses_another_type_and_a_retired_target_resolves_to_its_canonical(): void
    {
        $contentTag = (int) $this->row("SELECT id FROM tags WHERE name = 'Maritime'")['id'];
        $story = $this->makeStory('merge-wrong-type');
        $this->pendingRow($story, $this->memberId, 'Sea Stories');
        // Maritime lives in content; the suggestion sits in genre: 422
        $res = $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Sea Stories', 'target_id' => (string) $contentTag]);
        $this->assertSame(422, $res->status);
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE name = 'Sea Stories'"), 'the pending row survives a refused merge');
        // a retired target resolves to its canonical and attaches that
        $genre = $this->typeId('genre');
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES (?, ?, (SELECT id FROM tags WHERE name = ?))', [$genre, 'ret', 'Fantasy']);
        $retired = (int) $this->row("SELECT id FROM tags WHERE name = 'ret'")['id'];
        $res = $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $genre, 'name' => 'Sea Stories', 'target_id' => (string) $retired]);
        $this->assertSame(302, $res->status);
        $fantasy = (int) $this->row("SELECT id FROM tags WHERE name = 'Fantasy'")['id'];
        $this->assertSame($fantasy, (int) $this->row('SELECT tag_id FROM story_tags WHERE story_id = ?', [$story])['tag_id']);
    }

    public function test_reject_marks_rejected_and_attaches_nothing(): void
    {
        $story = $this->makeStory('reject-ride');
        $this->pendingRow($story, $this->memberId, 'Rejected Marker');
        $res = $this->admin()->postWithToken('/tagqueue/reject', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Rejected Marker']);
        $this->assertSame(302, $res->status);
        $row = $this->row("SELECT * FROM tag_suggestions WHERE name = 'Rejected Marker'");
        $this->assertSame('rejected', $row['status']);
        $this->assertSame($this->adminId, (int) $row['resolved_by']);
        $this->assertNotNull($row['resolved_at']);
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM story_tags WHERE story_id = ?', [$story]));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Rejected Marker'"));
        // a reject purges nothing: a planted cache file survives
        $file = $this->plantCachedPage();
        $this->pendingRow($this->makeStory('reject-quiet'), $this->memberId, 'Quiet Reject');
        $this->admin()->postWithToken('/tagqueue/reject', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Quiet Reject']);
        $this->assertFileExists($file, 'reject changes nothing public, so it purges nothing');
    }

    public function test_competing_decisions_cannot_send_stale_rejection_notices(): void
    {
        foreach (['approve', 'reject'] as $winner) {
            $name = 'Competing ' . $winner;
            $story = $this->makeStory('competing-' . $winner);
            $this->pendingRow($story, $this->memberId, $name);
            $post = ['type_id' => (string) $this->typeId('genre'), 'name' => $name];
            $app = $this->application();
            $client = (new \Kip\Testing\TestClient($app))->actingAs($this->adminId);
            $db = $app->container->make(\Kip\Database::class);
            $fired = false;
            $winningStatus = null;
            $db->onQuery(function (string $sql) use (&$fired, &$winningStatus, $winner, $post): void {
                if ($fired || !str_starts_with($sql, "UPDATE tag_suggestions SET status = 'rejected'")) return;
                $fired = true;
                // A separate request and connection commit just before this decision writes.
                $winningStatus = $this->admin()->postWithToken('/tagqueue/' . $winner, $post)->status;
            });
            try {
                $response = $client->postWithToken('/tagqueue/reject', $post);
            } finally {
                $db->onQuery(static fn (string $sql) => null);
            }
            $this->assertTrue($fired);
            $this->assertSame(302, $winningStatus);
            $this->assertSame(404, $response->status, 'the losing decision has no pending rows');
            $kind = $winner === 'approve' ? 'tag_suggestion_approved' : 'tag_suggestion_rejected';
            $this->assertSame([['kind' => $kind]], $this->db->all(
                "SELECT kind FROM notifications WHERE story_id = ? AND kind LIKE 'tag_suggestion_%'", [$story]));
            $this->assertSame($winner === 'approve' ? 1 : 0, $this->n(
                'SELECT COUNT(*) c FROM story_tags st JOIN tags t ON t.id = st.tag_id WHERE st.story_id = ? AND t.name = ?', [$story, $name]));
            $this->assertSame(404, $client->postWithToken('/tagqueue/reject', $post)->status, 'a retry stays resolved');
            $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM notifications WHERE story_id = ? AND kind LIKE 'tag_suggestion_%'", [$story]));
            $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM activity_log WHERE action = ? AND detail = ?",
                ['tag_suggestion.' . $winner, 'type ' . $this->typeId('genre') . ': ' . $name]));
        }
    }

    public function test_failed_rejection_leaves_every_row_pending_and_sends_no_notice(): void
    {
        $first = $this->makeStory('reject-rollback-first');
        $second = $this->makeStory('reject-rollback-second');
        $this->pendingRow($first, $this->memberId, 'Rollback Proposal');
        $last = $this->pendingRow($second, $this->authorId, 'Rollback Proposal');
        $this->db->query("CREATE TRIGGER reject_failure BEFORE UPDATE ON tag_suggestions
            WHEN NEW.status = 'rejected' AND OLD.id = {$last}
            BEGIN SELECT RAISE(ABORT, 'forced rejection failure'); END");
        $post = ['type_id' => (string) $this->typeId('genre'), 'name' => 'Rollback Proposal'];
        $this->assertSame(500, $this->admin()->postWithToken('/tagqueue/reject', $post)->status);
        $this->assertSame(2, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE name = 'Rollback Proposal' AND status = 'pending' AND resolved_at IS NULL"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM notifications WHERE kind = 'tag_suggestion_rejected'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM activity_log WHERE action = 'tag_suggestion.reject'"));
        $this->db->query('DROP TRIGGER reject_failure');
        $this->assertSame(302, $this->admin()->postWithToken('/tagqueue/reject', $post)->status);
        $this->assertSame(2, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE name = 'Rollback Proposal' AND status = 'rejected'"));
        $this->assertSame(2, $this->n("SELECT COUNT(*) c FROM notifications WHERE kind = 'tag_suggestion_rejected'"));
    }

    public function test_actions_require_the_csrf_token(): void
    {
        $story = $this->makeStory('csrf-ride');
        $this->pendingRow($story, $this->memberId, 'Guarded Marker');
        foreach (['approve', 'merge', 'reject'] as $action) {
            $res = $this->admin()->post('/tagqueue/' . $action, ['type_id' => (string) $this->typeId('genre'), 'name' => 'Guarded Marker', 'target_id' => '1']);
            $this->assertSame(403, $res->status, $action . ' without a token is refused');
        }
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE status = 'pending'"), 'nothing changed');
    }

    public function test_actions_require_admin(): void
    {
        $story = $this->makeStory('member-ride');
        $this->pendingRow($story, $this->memberId, 'Member Marker');
        foreach (['approve', 'merge', 'reject'] as $action) {
            $res = $this->client($this->memberId)->postWithToken('/tagqueue/' . $action, ['type_id' => (string) $this->typeId('genre'), 'name' => 'Member Marker', 'target_id' => '1']);
            $this->assertSame(403, $res->status, $action . ' as a member is refused');
        }
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE status = 'pending'"), 'nothing changed');
    }

    public function test_unknown_suggestion_key_is_404(): void
    {
        foreach (['approve', 'merge', 'reject'] as $action) {
            $res = $this->admin()->postWithToken('/tagqueue/' . $action, ['type_id' => (string) $this->typeId('genre'), 'name' => 'Never Suggested Marker', 'target_id' => '1']);
            $this->assertSame(404, $res->status, $action . ' with no pending rows is 404');
        }
    }

    public function test_approve_and_merge_purge_the_cache_and_reject_does_not(): void
    {
        $file = $this->plantCachedPage();
        $story = $this->makeStory('purge-approve');
        $this->pendingRow($story, $this->memberId, 'Purge Marker');
        $this->admin()->postWithToken('/tagqueue/approve', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Purge Marker']);
        $this->assertFileDoesNotExist($file, 'approve changed public pages, so it purged');
        $file = $this->plantCachedPage();
        $this->pendingRow($this->makeStory('purge-merge'), $this->memberId, 'Purge Merge');
        $this->admin()->postWithToken('/tagqueue/merge', ['type_id' => (string) $this->typeId('genre'), 'name' => 'Purge Merge', 'target_id' => (string) $this->row("SELECT id FROM tags WHERE name = 'Fantasy'")['id']]);
        $this->assertFileDoesNotExist($file, 'merge changed public pages, so it purged');
    }

    public function test_approve_is_atomic(): void
    {
        $story = $this->makeStory('atomic-ride');
        $this->pendingRow($story, $this->memberId, 'Atomic Marker');
        // force a failure after the tag insert: a trigger that RAISEs on story_tags
        $this->db->query("CREATE TRIGGER boom BEFORE INSERT ON story_tags BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $repo = new \App\Repositories\TagSuggestionRepository($this->db);
        try {
            $this->db->begin();
            $repo->approve($this->typeId('genre'), 'Atomic Marker', $this->adminId);
            $this->fail('the forced failure did not fire');
        } catch (\PDOException) {
            $this->db->rollBack();
        }
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM tags WHERE name = 'Atomic Marker'"), 'the tag rolled back');
        $this->assertSame(1, $this->n("SELECT COUNT(*) c FROM tag_suggestions WHERE status = 'pending'"), 'the pending rows survived');
    }
}
