<?php // tests/MailUsersTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** mail:users batch CLI through CliTest's subprocess idiom: eligibility (the
 *  directory gate), the --dry-run/--commit modes, the --template override and
 *  the --mail-log operator seam (tests never touch the repo's app/mail.log). */
final class MailUsersTest extends TestCase
{
    private string $path = '';
    private string $root = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mailusers-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-mailusers-' . uniqid('', true);
        mkdir($this->root, 0775, true);
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    /** @return array{0: int, 1: string} exit code, stdout+stderr */
    private function kip(string $args): array
    {
        $cmd = sprintf('KIP_DB_DSN=%s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    public function test_mail_users_dry_run_and_commit(): void
    {
        $db = $this->db();
        // Four excluded fixture rows, one per gate leg: unapproved, unverified,
        // locked, and penname-less. The eligible count must stay at 2.
        $db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, NULL, ?)',
            ['pending@e.test', password_hash('password123', PASSWORD_DEFAULT), 'pendingpen', date('c'), 'pendingpen']);
        $db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, NULL, ?, ?)',
            ['unverified@e.test', password_hash('password123', PASSWORD_DEFAULT), 'unverifiedpen', date('c'), 'unverifiedpen']);
        $db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, is_locked, profile_slug) VALUES (?, ?, ?, ?, ?, 1, ?)',
            ['locked@e.test', password_hash('password123', PASSWORD_DEFAULT), 'lockedpen', date('c'), date('c'), 'lockedpen']);
        $db->query('INSERT INTO users (email, password_hash, email_verified_at, approved_at) VALUES (?, ?, ?, ?)',
            ['nopename@e.test', password_hash('password123', PASSWORD_DEFAULT), date('c'), date('c')]);

        $log = $this->root . '/mail.log';
        [$code, $out] = $this->kip('mail:users "Subject here" "Hello members" --dry-run --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('would mail 2 recipient(s)', $out); // demo author + betafriend
        $this->assertStringContainsString('demo@example.test', $out, 'the first five addresses print');
        $this->assertStringContainsString('beta@example.test', $out);
        $this->assertStringNotContainsString('pending@e.test', $out);
        $this->assertStringNotContainsString('unverified@e.test', $out);
        $this->assertStringNotContainsString('locked@e.test', $out);
        $this->assertStringNotContainsString('nopename@e.test', $out);
        $this->assertFileDoesNotExist($log, 'dry-run never sends and never writes the log');

        [$code2, $out2] = $this->kip('mail:users "Subject here" "Hello members" --commit --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('mailed 2', $out2);
        $mail = (string) file_get_contents($log);
        $this->assertStringContainsString('demo@example.test', $mail);
        $this->assertStringContainsString('beta@example.test', $mail);
        $this->assertStringContainsString('Subject here', $mail);
        $this->assertStringContainsString('Hello members', $mail);
        $this->assertStringNotContainsString('pending@e.test', $mail);
    }

    public function test_mail_users_dry_run_lists_at_most_five_addresses(): void
    {
        $db = $this->db();
        foreach (['bulk1@e.test', 'bulk2@e.test', 'bulk3@e.test', 'bulk4@e.test'] as $i => $email) {
            $db->query('INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?)',
                [$email, password_hash('password123', PASSWORD_DEFAULT), 'bulkpen' . $i, date('c'), date('c'), 'bulkpen' . $i]);
        }
        [, $out] = $this->kip('mail:users "S" "B" --dry-run --mail-log=' . escapeshellarg($this->root . '/mail.log'));
        $this->assertStringContainsString('would mail 6 recipient(s)', $out);
        $this->assertStringContainsString('bulk3@e.test', $out, 'the fifth address still prints');
        $this->assertStringNotContainsString('bulk4@e.test', $out, 'the sixth address is capped out');
    }

    public function test_mail_users_with_zero_eligible_prints_zero_cleanly(): void
    {
        $this->db()->query('UPDATE users SET is_locked = 1');
        $log = $this->root . '/mail.log';
        [$code, $out] = $this->kip('mail:users "S" "B" --commit --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('mailed 0', $out);
        $this->assertFileDoesNotExist($log, 'nothing sent, nothing logged');
        [$code2, $out2] = $this->kip('mail:users "S" "B" --dry-run --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('would mail 0 recipient(s)', $out2);
    }

    public function test_mail_users_template_overrides_and_falls_back(): void
    {
        $db = $this->db();
        $db->query('INSERT INTO mail_templates (name, subject, body) VALUES (?, ?, ?)',
            ['maintenance', 'Scheduled maintenance', 'The archive pauses on {date}.']);
        $log = $this->root . '/mail.log';
        [$code, $out] = $this->kip('mail:users "Literal subject" "Literal body" --template=maintenance --commit --mail-log=' . escapeshellarg($log));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('mailed 2', $out);
        $mail = (string) file_get_contents($log);
        $this->assertStringContainsString('Scheduled maintenance', $mail, 'the saved template wins over the literals');
        $this->assertStringContainsString('{date}', $mail, 'placeholders pass through uninterpolated');
        $this->assertStringNotContainsString('Literal subject', $mail);

        // A missing name falls back to the literal pair with a note; the CLI
        // reads templates, it never seeds them.
        [$code2, $out2] = $this->kip('mail:users "Fallback subject" "Fallback body" --template=nonexistent --dry-run --mail-log=' . escapeshellarg($this->root . '/other.log'));
        $this->assertSame(0, $code2, $out2);
        $this->assertStringContainsString('would mail 2 recipient(s)', $out2);
        $this->assertStringContainsString("Template 'nonexistent' not found", $out2);
        $this->assertSame(1, (int) $db->one('SELECT COUNT(*) c FROM mail_templates')['c'],
            'seedDefaults is never auto-run by the CLI');
    }

    public function test_mail_users_usage_gates_and_help_line(): void
    {
        [$code, $out] = $this->kip('mail:users "Only a subject"');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip mail:users', $out);
        [$code2] = $this->kip('mail:users "S" "B"');
        $this->assertSame(1, $code2, 'a --dry-run/--commit mode flag is required');
        [, $help] = $this->kip('');
        $this->assertStringContainsString('mail:users', $help, 'the main usage line lists the arm');
    }
}
