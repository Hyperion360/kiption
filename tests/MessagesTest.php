<?php // tests/MessagesTest.php
namespace App\Tests;
use App\Notifications;
use App\Repositories\MessageRepository;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MessagesTest extends TestCase
{
    private string $path = '';
    private string $mailLog = '';
    private Database $db;
    private int $memberRowId = 0;
    private int $authorRowId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-pm-') . '.sqlite';
        $this->mailLog = tempnam(sys_get_temp_dir(), 'kiption-pm-mail-') . '.log';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($this->db);
        $this->memberRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->authorRowId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    protected function tearDown(): void
    {
        // The FeaturesTest discipline: any test that inits the flag resolver
        // must drop its state, or a later suite in this process inherits a
        // memo pointing at an unlinking temp DB.
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink($this->mailLog);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return $this->memberRowId;
    }

    private function authorId(): int
    {
        return $this->authorRowId;
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->mailLog, 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-pm-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    public function test_send_reply_and_the_inbox_fold(): void
    {
        $alice = $this->client($this->memberId());   // betafriend
        $this->assertSame(302, $alice->postWithToken('/messages/send/demo-author', ['body' => 'Hello there.'])->status);
        $bob = $this->client($this->authorId());      // Demo Author
        $thread = $bob->get('/messages/view/betafriend');
        $this->assertSame(200, $thread->status);
        $this->assertStringContainsString('Hello there.', $thread->body);
        $this->assertSame(302, $bob->postWithToken('/messages/send/betafriend', ['body' => 'Hello back.'])->status);
        // bob's inbox: ONE row per thread (the href count, not the bare name:
        // the penname and the slug are the same string in the seed, finding 15)
        // with the thread's latest message as the preview.
        $inbox = $bob->get('/messages')->body;
        $this->assertSame(1, substr_count($inbox, '/messages/view/betafriend'), 'one thread row');
        $this->assertStringContainsString('Hello back.', $inbox);
        // the unread badge rides the side with fresh incoming mail: bob already
        // opened the thread (marking alice's message read), so the live badge is
        // ALICE's, whose thread now carries bob's unread reply.
        $this->assertStringContainsString('unread', $alice->get('/messages')->body);
        // alice opens her thread: nothing addressed to Demo Author stays unread
        // (bob's earlier open marked alice's message, alice's open marks his)
        $alice->get('/messages/view/demo-author');
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM messages WHERE recipient_id = ? AND read_at IS NULL', [$this->authorId()])['c']);
        // the pm notification landed in bob's inbox
        $this->assertStringContainsString('message', strtolower($bob->get('/notifications')->body));
    }

    public function test_message_gates(): void
    {
        $me = $this->client($this->memberId());
        // guests bounce to the login redirect on every surface, token or no token
        $this->assertSame(302, $this->client()->get('/messages')->status);
        $this->assertSame(302, $this->client()->get('/messages/view/demo-author')->status);
        // self-send 404s exactly like an unknown slug: no channel to yourself
        $this->assertSame(404, $me->postWithToken('/messages/send/betafriend', ['body' => 'note to self'])->status);
        $this->assertSame(404, $me->postWithToken('/messages/send/ghost', ['body' => 'hi'])->status);
        // the contact clamp: empty and oversized bodies re-render the form at 422
        $this->assertSame(422, $me->postWithToken('/messages/send/demo-author', ['body' => ''])->status);
        $this->assertSame(422, $me->postWithToken('/messages/send/demo-author', ['body' => str_repeat('y', 5001)])->status);
        // nothing was written by any rejected path
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM messages')['c']);
    }

    public function test_the_compose_surface(): void
    {
        $me = $this->client($this->memberId());
        $form = $me->get('/messages/new/demo-author');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('action="/messages/send/demo-author"', $form->body);
        // the thread page carries the same reply form, even for an empty thread
        $emptyThread = $me->get('/messages/view/demo-author');
        $this->assertSame(200, $emptyThread->status);
        $this->assertStringContainsString('action="/messages/send/demo-author"', $emptyThread->body);
        // self and unknown targets 404 on the compose surface too; guests redirect
        $this->assertSame(404, $me->get('/messages/new/betafriend')->status);
        $this->assertSame(404, $me->get('/messages/new/ghost')->status);
        $this->assertSame(302, $this->client()->get('/messages/new/demo-author')->status);
    }

    public function test_the_thread_windows_the_newest_200_oldest_first(): void
    {
        // 205 messages from Demo Author to betafriend, one per second: the
        // derived-table window keeps the NEWEST 200 and drops the oldest five
        // (finding 3: oldest-first + LIMIT would drop the newest tail), and the
        // outer ordering renders them oldest-first.
        $base = 1767225600; // 2026-01-01T00:00:00Z
        for ($i = 1; $i <= 205; $i++) {
            $this->db()->query(
                'INSERT INTO messages (sender_id, recipient_id, body, created_at) VALUES (?, ?, ?, ?)',
                [$this->authorId(), $this->memberId(), sprintf('msg-%03d', $i), gmdate('Y-m-d\TH:i:s\Z', $base + $i)]
            );
        }
        $body = $this->client($this->memberId())->get('/messages/view/demo-author')->body;
        $this->assertStringNotContainsString('msg-001', $body, 'the oldest message is outside the window');
        $this->assertStringNotContainsString('msg-005', $body, 'the fifth-oldest message is outside the window');
        $this->assertStringContainsString('msg-006', $body, 'the newest 200 start at the sixth-oldest');
        $this->assertStringContainsString('msg-205', $body, 'the newest message is inside the window');
        $this->assertLessThan(strpos($body, 'msg-007'), strpos($body, 'msg-006'), 'oldest first');
        $this->assertLessThan(strpos($body, 'msg-205'), strpos($body, 'msg-100'), 'oldest first throughout');
        // the mark-read UPDATE covers the whole thread, not just the window
        $this->assertSame(0, (int) $this->db()->one('SELECT COUNT(*) c FROM messages WHERE recipient_id = ? AND read_at IS NULL', [$this->memberId()])['c']);
    }

    public function test_the_inbox_fold_is_one_row_per_thread(): void
    {
        // a third member so the fold must actually group: two conversations,
        // the alice one carrying messages in BOTH directions (the normalized
        // pair groups them into a single thread however the pair alternates).
        $this->db()->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('carol@example.test', ?, 'Carol', ?, ?, 'carol')",
            [password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);
        $carol = (int) $this->db()->one("SELECT id FROM users WHERE penname = 'Carol'")['id'];
        $repo = new MessageRepository($this->db());
        $repo->send($this->memberId(), $this->authorId(), 'First thread A');
        $repo->send($this->authorId(), $this->memberId(), 'First thread B');
        $repo->send($carol, $this->authorId(), 'Second thread');
        $rows = $repo->inbox($this->authorId());
        $this->assertCount(2, $rows);
        // newest conversation first; each row carries its partner and only the
        // viewer's incoming unread count
        $this->assertSame('carol', $rows[0]['partner_slug']);
        $this->assertSame(1, (int) $rows[0]['unread']);
        $this->assertSame('betafriend', $rows[1]['partner_slug']);
        $this->assertSame('First thread B', $rows[1]['body'], 'the preview is the thread\'s latest message');
        $this->assertSame(1, (int) $rows[1]['unread'], 'only incoming mail counts: bob\'s own reply never badges him');
        // over HTTP the fold renders one link per conversation
        $inbox = $this->client($this->authorId())->get('/messages')->body;
        $this->assertSame(2, substr_count($inbox, '/messages/view/'));
    }

    public function test_marking_read_is_idempotent(): void
    {
        $repo = new MessageRepository($this->db());
        $repo->send($this->authorId(), $this->memberId(), 'one');
        $this->assertSame(1, (int) $repo->inbox($this->memberId())[0]['unread']);
        $rows = $repo->thread($this->memberId(), 'demo-author');
        $this->assertCount(1, $rows); // the anchor row carries the one message
        $this->assertSame(0, (int) $repo->inbox($this->memberId())[0]['unread'], 'the open cleared the badge');
        $readAt = $this->db()->one('SELECT read_at FROM messages')['read_at'];
        $this->assertNotNull($readAt);
        $repo->thread($this->memberId(), 'demo-author'); // a second open is a no-op
        $this->assertSame($readAt, $this->db()->one('SELECT read_at FROM messages')['read_at']);
    }

    public function test_the_pm_notification_links_the_thread(): void
    {
        $repo = new MessageRepository($this->db());
        $repo->send($this->memberId(), $this->authorId(), 'ping');
        $rows = (new Notifications($this->db))->inboxRows($this->authorId());
        $this->assertSame('pm', $rows[0]['kind']);
        $this->assertSame('betafriend', $rows[0]['actor_slug'], 'the actor_slug scalar fold (finding 5)');
        $body = $this->client($this->authorId())->get('/notifications')->body;
        $this->assertStringContainsString('/messages/view/betafriend', $body);
        $this->assertStringContainsString('sent you a message', $body);
    }

    public function test_bodies_render_as_markdown_at_rest(): void
    {
        // the review precedent: stored bodies render through the same pipeline,
        // so raw HTML is unstoreable and emphasis syntax comes out as markup
        $this->client($this->memberId())->postWithToken('/messages/send/demo-author', ['body' => 'Some *emphasis* here.']);
        $thread = $this->client($this->authorId())->get('/messages/view/betafriend')->body;
        $this->assertStringContainsString('<em>emphasis</em>', $thread);
    }

    public function test_the_layout_link_rides_member_pages(): void
    {
        // Messages sits beside Notifications in the member chrome and never
        // renders for guests
        $this->assertStringContainsString('href="/messages"', $this->client($this->memberId())->get('/account')->body);
        $this->assertStringNotContainsString('href="/messages"', $this->client()->get('/account')->body);
    }

    public function test_flag_off_404s_every_action_and_hides_the_link(): void
    {
        $db = $this->db();
        $db->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['pms']);
        \App\Features::init($db, []); // the public/index.php init, pointed at this throwaway DB
        $me = $this->client($this->memberId());
        $this->assertSame(404, $me->get('/messages')->status);
        $this->assertSame(404, $me->get('/messages/view/demo-author')->status);
        $this->assertSame(404, $me->get('/messages/new/demo-author')->status);
        $this->assertSame(404, $me->postWithToken('/messages/send/demo-author', ['body' => 'hi'])->status);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) c FROM messages')['c']);
        $this->assertStringNotContainsString('href="/messages"', $me->get('/account')->body);
    }
}
