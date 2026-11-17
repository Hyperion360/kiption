<?php // tests/MenuCountsTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The account menu and the phone sheet say how many Library stories are in
 *  progress and how many stories the member has written. Both numbers ride
 *  the session-check statement, so no page gains a statement. */
#[Medium]
final class MenuCountsTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-menucounts-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES ('mc@e.test', ?, 'menucounter', ?, ?)",
            [\App\Tests\Support\Hashes::of('password123'), date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        // two stories of their own (one deleted), three others they read: one finished, one deleted, one open
        foreach ([['own-a', $this->memberId, null], ['own-b', $this->memberId, null], ['own-gone', $this->memberId, date('c')],
                  ['read-a', 1, null], ['read-b', 1, null], ['read-gone', 1, date('c')]] as [$slug, $author, $deleted]) {
            $this->db->query('INSERT INTO stories (title, slug, summary, author_id, rating_id, validated, deleted_at) VALUES (?, ?, \'S.\', ?, 1, 1, ?)', [$slug, $slug, $author, $deleted]);
            $ids[$slug] = (int) $this->db->lastInsertId();
        }
        $this->db->query('INSERT INTO reading_history (user_id, story_id, marked_at) VALUES (?, ?, NULL)', [$this->memberId, $ids['read-a']]);
        $this->db->query('INSERT INTO reading_history (user_id, story_id, marked_at) VALUES (?, ?, ?)', [$this->memberId, $ids['read-b'], date('c')]);
        $this->db->query('INSERT INTO reading_history (user_id, story_id, marked_at) VALUES (?, ?, NULL)', [$this->memberId, $ids['read-gone']]);
        $this->db->query('INSERT INTO reading_history (user_id, story_id, marked_at) VALUES (?, ?, NULL)', [$this->memberId, $ids['own-a']]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function app(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-menucounts.log', 'from' => 'noreply@kiption.test'],
        ]);
    }

    public function test_the_menus_show_in_progress_and_own_story_counts(): void
    {
        $body = (new TestClient($this->app()))->actingAs($this->memberId)->get('/browse')->body;
        $this->assertStringContainsString('<small class="nav-me-note">2 in progress</small>', $body, 'open, not finished, not deleted: read-a and own-a');
        $this->assertStringContainsString('<small class="nav-me-note">2</small>', $body, 'own-a and own-b; the deleted one is not counted');
        $this->assertStringContainsString('<small>2 in progress</small>', $body, 'the phone sheet carries it too');
        $this->assertStringContainsString('<small>2 stories</small>', $body);
    }

    public function test_nothing_is_said_when_the_counts_are_zero_and_guests_see_none(): void
    {
        $this->db->query('DELETE FROM reading_history WHERE user_id = ?', [$this->memberId]);
        $this->db->query('UPDATE stories SET deleted_at = ? WHERE author_id = ?', [date('c'), $this->memberId]);
        $body = (new TestClient($this->app()))->actingAs($this->memberId)->get('/browse')->body;
        $this->assertStringNotContainsString('in progress', $body);
        $this->assertStringNotContainsString('0 stories', $body);
        $guest = (new TestClient($this->app()))->get('/browse')->body;
        $this->assertStringNotContainsString('in progress', $guest);
    }

    public function test_the_counts_add_no_statement_to_a_member_page(): void
    {
        $app = $this->app();
        $db = $app->container->make(Database::class);
        $seen = [];
        \App\Tests\Support\QueryTap::on($db, function (string $sql) use (&$seen): void { $seen[] = $sql; });
        $body = (new TestClient($app))->actingAs($this->memberId)->get('/browse')->body;
        \App\Tests\Support\QueryTap::off($db);
        $this->assertStringContainsString('2 in progress', $body);
        $guard = array_filter($seen, fn (string $q): bool => $q === \App\SessionGuard::SQL);
        $this->assertCount(1, $guard, 'the session check still runs once, and carries the counts');
        $this->assertStringContainsString('in_progress', \App\SessionGuard::SQL);
        $content = array_filter($seen, fn (string $q): bool => $q !== \App\SessionGuard::SQL && $q !== 'SELECT password_hash FROM users WHERE id = ?');
        $this->assertLessThanOrEqual(1, count($content), 'the page itself still runs one content statement');
    }
}
