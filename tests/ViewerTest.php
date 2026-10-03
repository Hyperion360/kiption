<?php // tests/ViewerTest.php
namespace App\Tests;
use App\Viewer;
use Kip\{Auth, Database, Session};
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** App\Viewer decides who a public page renders for: the cookieless path is
 *  a guest without touching the session, a live session is the member, and
 *  a session revoked by a password change is a guest again. */
final class ViewerTest extends TestCase
{
    private Database $db;
    private int $memberId;

    protected function setUp(): void
    {
        $this->db = new Database('sqlite::memory:');
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->db->query("INSERT INTO users (email, password_hash, penname) VALUES ('v@e.test', ?, 'viewer')", [password_hash('first', PASSWORD_DEFAULT)]);
        $this->memberId = (int) $this->db->lastInsertId();
    }

    private function loggedInSession(): Session
    {
        $store = [];
        $session = new Session($store);
        $session->set('user_id', $this->memberId);
        $hash = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$this->memberId])['password_hash'];
        $session->set('pwd_epoch', substr($hash, 0, Auth::EPOCH_LEN));
        return $session;
    }

    private function withCookie(): Request
    {
        return new Request('GET', '/', [], [], ['kip_session' => 'x']);
    }

    public function test_a_cookieless_request_is_a_guest_even_with_a_session(): void
    {
        $this->assertSame(0, Viewer::id(new Request('GET', '/', [], [], []), $this->loggedInSession(), $this->db));
    }

    public function test_a_live_session_is_the_member(): void
    {
        $this->assertSame($this->memberId, Viewer::id($this->withCookie(), $this->loggedInSession(), $this->db));
    }

    public function test_a_cookie_without_a_login_is_a_guest(): void
    {
        $store = [];
        $this->assertSame(0, Viewer::id($this->withCookie(), new Session($store), $this->db));
    }

    public function test_a_session_revoked_by_a_password_change_is_a_guest(): void
    {
        $session = $this->loggedInSession();
        $this->db->query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('second', PASSWORD_DEFAULT), $this->memberId]);
        $this->assertSame(0, Viewer::id($this->withCookie(), $session, $this->db));
    }
}
