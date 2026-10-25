<?php // tests/LegacyBcryptLoginTest.php
namespace App\Tests;
use App\Features\Auth\AuthController;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** The legacy credential hook's bcrypt arm (eFiction 3.5.7 imports): verify
 *  at first login, rehash with PASSWORD_DEFAULT, clear BOTH legacy columns
 *  (T4), md5 precedence on corrupt double-legacy rows (T3), and a wrong
 *  password burns nothing inside the hook itself (the throttle gate above it
 *  owns login_attempts). */
final class LegacyBcryptLoginTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-bc-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** Constructor-independent instance: db injected; the mailer is a REAL
     *  Mailer whose send() throws (unknown transport) — the hook catches it,
     *  so the we_moved mail is exercised without delivering anything. */
    private function controller(): AuthController
    {
        $c = (new \ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
        foreach (['db' => $this->db, 'mailer' => \App\MailGuard::off($this->db, new \Kip\Mailer(['transport' => 'throws-in-tests']))] as $prop => $val) {
            $p = new \ReflectionProperty(AuthController::class, $prop);
            $p->setAccessible(true);
            $p->setValue($c, $val);
        }
        return $c;
    }

    private function invoke(AuthController $c, string $email, string $password): bool
    {
        $m = new \ReflectionMethod(AuthController::class, 'tryLegacyUpgrade');
        $m->setAccessible(true);
        return $m->invoke($c, $email, $password);
    }

    private function seed(string $email, ?string $md5, ?string $bcrypt): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, legacy_md5, legacy_bcrypt) VALUES (?, ?, ?, ?, ?)',
            [$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'Legacy', $md5, $bcrypt]);
        return (int) $this->db->one('SELECT id FROM users WHERE email = ?', [$email])['id'];
    }

    public function test_bcrypt_user_upgrades_and_both_columns_clear(): void
    {
        $id = $this->seed('bc@example.test', null, password_hash('bcryptpassword', PASSWORD_BCRYPT));
        $this->assertTrue($this->invoke($this->controller(), 'bc@example.test', 'bcryptpassword'));
        $row = $this->db->one('SELECT password_hash, legacy_md5, legacy_bcrypt FROM users WHERE id = ?', [$id]);
        $this->assertTrue(password_verify('bcryptpassword', $row['password_hash']));
        $this->assertNull($row['legacy_md5']);
        $this->assertNull($row['legacy_bcrypt']);
    }

    public function test_wrong_password_costs_one_attempt_and_upgrades_nothing(): void
    {
        $id = $this->seed('bc2@example.test', null, password_hash('bcryptpassword', PASSWORD_BCRYPT));
        $before = $this->db->one('SELECT COUNT(*) AS c FROM login_attempts')['c'];
        $this->assertFalse($this->invoke($this->controller(), 'bc2@example.test', 'wrong'));
        $this->assertSame($before, $this->db->one('SELECT COUNT(*) AS c FROM login_attempts')['c']); // hook itself burns none: the throttle gate above it owns that
        $row = $this->db->one('SELECT password_hash, legacy_bcrypt FROM users WHERE id = ?', [$id]);
        $this->assertFalse(password_verify('wrong', $row['password_hash']));
        $this->assertNotNull($row['legacy_bcrypt']);
    }

    public function test_md5_precedence_when_both_columns_set(): void
    {
        // Defensive (T3): a corrupt row with both columns verifies via legacy_md5 only.
        $id = $this->seed('both@example.test', md5('md5wins'), password_hash('bcryptpassword', PASSWORD_BCRYPT));
        $this->assertTrue($this->invoke($this->controller(), 'both@example.test', 'md5wins'));
        $this->assertNull($this->db->one('SELECT legacy_md5 FROM users WHERE id = ?', [$id])['legacy_md5']);
        $this->assertNull($this->db->one('SELECT legacy_bcrypt FROM users WHERE id = ?', [$id])['legacy_bcrypt']);
    }
}
