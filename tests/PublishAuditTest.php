<?php // tests/PublishAuditTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/publish-audit: the publish gate. Fixture repos (git init + commits in
 * temp dirs) trip each named check individually; a clean fixture passes; the
 * real repo this test lives in passes. Fixture secret and boundary strings
 * are built by runtime concatenation so this tracked test file never
 * contains a contiguous probe string (the boundary check greps tracked
 * files, the secret check scans history, and both would find it here).
 */
final class PublishAuditTest extends TestCase
{
    /** @var string[] fixture roots, removed in tearDown */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) exec('rm -rf ' . escapeshellarg($d));
        $this->dirs = [];
    }

    /** @return array{0: int, 1: string} exit code, combined output */
    private function audit(string $args = ''): array
    {
        $cmd = sprintf('%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/publish-audit'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    /**
     * A publishable-minimum repo: the four required docs, an empty config,
     * one source file, one clean commit.
     */
    private function fixture(): string
    {
        $dir = sys_get_temp_dir() . '/kiption-pubaudit-' . uniqid();
        $this->dirs[] = $dir;
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/README.md', "# Fixture archive\n\nSelf-hosted fiction archive. See INSTALL.md.\n");
        file_put_contents($dir . '/LICENSE', "MIT License. Copyright (c) fixture.\n");
        file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n");
        file_put_contents($dir . '/INSTALL.md', "# Install\n\n    composer install\n    php bin/kip migrate\n");
        file_put_contents($dir . '/config.php', "<?php return [];\n");
        file_put_contents($dir . '/src/Widget.php', "<?php\n// fixture source\n");
        exec(sprintf('git -C %s init -q -b main', escapeshellarg($dir)));
        $this->commit($dir, 'init');
        return $dir;
    }

    private function commit(string $dir, string $msg): void
    {
        exec(sprintf('git -C %s add -A && git -C %s -c user.name=Fixture -c user.email=fixture@e.test commit -qm %s',
            escapeshellarg($dir), escapeshellarg($dir), escapeshellarg($msg)), $out, $code);
        $this->assertSame(0, $code, 'fixture commit failed: ' . implode("\n", $out));
    }

    public function test_clean_fixture_passes_all_five_checks(): void
    {
        [$code, $out] = $this->audit('--path ' . escapeshellarg($this->fixture()));
        $this->assertSame(0, $code, $out);
        foreach (['tree', 'history', 'secrets', 'boundary', 'docs'] as $name) {
            $this->assertStringContainsString("[ ok ] {$name}", $out, $out);
        }
        $this->assertStringContainsString('All checks passed', $out);
    }

    public function test_clean_fixture_json_mode_agrees(): void
    {
        [$code, $out] = $this->audit('--json --path ' . escapeshellarg($this->fixture()));
        $this->assertSame(0, $code, $out);
        $data = json_decode($out, true);
        $this->assertIsArray($data, $out);
        $this->assertTrue($data['ok']);
        $this->assertSame(0, $data['failures']);
        $this->assertCount(5, $data['checks']);
        foreach ($data['checks'] as $c) $this->assertTrue($c['ok'], json_encode($c));
    }

    public function test_tracked_sqlite_fails_the_tree_check(): void
    {
        $dir = $this->fixture();
        file_put_contents($dir . '/data.sqlite', 'sqlite fixture bytes');
        $this->commit($dir, 'add db');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] tree', $out);
        $this->assertStringContainsString('data.sqlite', $out);
        $this->assertStringContainsString('tracked', $out);
        // A tracked data.sqlite also trips history (it was added in a
        // commit); the other checks stay clean, so the failure is named.
        $this->assertStringContainsString('[FAIL] history', $out);
        $this->assertStringContainsString('[ ok ] secrets', $out);
        $this->assertStringContainsString('[ ok ] boundary', $out);
        $this->assertStringContainsString('[ ok ] docs', $out);
    }

    public function test_addable_env_file_fails_the_tree_check(): void
    {
        // Present, untracked, and NOT ignored: one `git add -A` ships it.
        $dir = $this->fixture();
        file_put_contents($dir . '/.env', "MAIL_PASSWORD=nope\n");
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] tree', $out);
        $this->assertStringContainsString('.env', $out);
        $this->assertStringContainsString('addable', $out);
    }

    public function test_addable_qa_full_report_fails_the_tree_check(): void
    {
        // The qa-full pipeline writes its report into qa-full-reports/ beside
        // the tree; before the rule existed the audit called such a tree
        // publishable while `git add -A` staged the internal reports (and
        // home.html, router.php scratch artifacts) for the public remote.
        $dir = $this->fixture();
        mkdir($dir . '/qa-full-reports');
        file_put_contents($dir . '/qa-full-reports/main-2026-10-04.md', "# internal QA report\n");
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] tree', $out);
        // git status reports an untracked non-empty directory as one path.
        $this->assertStringContainsString('qa-full-reports/', $out);
        $this->assertStringContainsString('addable', $out);
    }

    public function test_env_in_history_fails_the_history_check_even_after_removal(): void
    {
        $dir = $this->fixture();
        file_put_contents($dir . '/.env', "SECRET=1\n");
        $this->commit($dir, 'add env');
        exec(sprintf('git -C %s rm -q .env', escapeshellarg($dir)));
        $this->commit($dir, 'drop env');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[ ok ] tree', $out); // the tree is clean; only history trips
        $this->assertStringContainsString('[FAIL] history', $out);
        $this->assertStringContainsString('added .env', $out);
    }

    public function test_aws_token_in_an_old_commit_fails_the_secret_check(): void
    {
        $dir = $this->fixture();
        // Concatenated: this test file must never hold the contiguous shape.
        $token = 'AK' . 'IAABCDEFGHIJKLMNOP';
        file_put_contents($dir . '/config.php', "<?php return ['aws' => '{$token}'];\n");
        $this->commit($dir, 'add config with key');
        file_put_contents($dir . '/config.php', "<?php return [];\n");
        $this->commit($dir, 'scrub config');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[ ok ] tree', $out); // the tree is clean; only history trips
        $this->assertStringContainsString('[FAIL] secrets', $out);
        $this->assertStringContainsString('aws-access-key', $out);
        $this->assertStringContainsString('config.php', $out);
    }

    public function test_boundary_reference_fails_the_boundary_check(): void
    {
        $dir = $this->fixture();
        // Concatenated so this tracked test file never carries the probe string.
        $private = 'kiption' . '-' . 'cloud';
        file_put_contents($dir . '/src/notes.md', "Ops live in {$private}; see its runbook.\n");
        $this->commit($dir, 'add notes');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] boundary', $out);
        $this->assertStringContainsString('src/notes.md', $out);
        $this->assertStringContainsString('1 check(s) failed', $out);
    }

    public function test_missing_license_fails_the_docs_check(): void
    {
        $dir = $this->fixture();
        unlink($dir . '/LICENSE');
        $this->commit($dir, 'drop license');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] docs', $out);
        $this->assertStringContainsString('LICENSE', $out);

        [$code, $json] = $this->audit('--json --path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $json); // json mode keeps the gate exit code
        $data = json_decode($json, true);
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['failures']);
        $docs = array_values(array_filter($data['checks'], fn($c) => $c['name'] === 'docs'))[0];
        $this->assertFalse($docs['ok']);
    }

    public function test_stale_bootstrap_milestone_readme_fails_the_docs_check(): void
    {
        $dir = $this->fixture();
        file_put_contents($dir . '/README.md', "# Fixture archive\n\nThis is the bootstrap milestone build.\n");
        $this->commit($dir, 'stale readme');
        [$code, $out] = $this->audit('--path ' . escapeshellarg($dir));
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('[FAIL] docs', $out);
        $this->assertStringContainsString('bootstrap milestone', $out);
    }

    public function test_the_real_repo_passes_the_audit(): void
    {
        // No --path: the script audits the repo it lives in (this worktree).
        [$code, $out] = $this->audit();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('All checks passed', $out);
    }
}
