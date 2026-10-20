<?php // tests/CliTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** End-to-end CLI checks: bin/kip runs against a throwaway DB via KIP_DB_DSN. */
final class CliTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-cli-') . '.sqlite';
    }

    private string $fixtures = '';

    protected function tearDown(): void
    {
        if ($this->fixtures !== '') { exec('rm -rf ' . escapeshellarg($this->fixtures)); $this->fixtures = ''; }
        $staticDir = $this->path . '-static';
        if (is_dir($staticDir)) { exec('rm -rf ' . escapeshellarg($staticDir)); }
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @return array{0: int, 1: string} exit code, stdout */
    private function kip(string $args, string $cacheDsn = '', string $extraEnv = ''): array
    {
        // KIP_STATIC_CACHE_DIR always points at a throwaway dir: cache:clear
        // purges the static layer too, and the live public/cache must never
        // be touched from a test (the same isolation KIP_CACHE_DB_DSN gives
        // the framework cache database).
        $staticDir = $this->path . '-static';
        if (!is_dir($staticDir)) { mkdir($staticDir); }
        $cmd = sprintf('KIP_DB_DSN=%s%s KIP_STATIC_CACHE_DIR=%s %s %s %s %s 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            $cacheDsn === '' ? '' : ' KIP_CACHE_DB_DSN=' . escapeshellarg($cacheDsn),
            escapeshellarg($staticDir),
            $extraEnv,
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args);
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_user_create_makes_an_active_admin_who_can_log_in(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('user:create cli@e.test password123 --admin');
        $this->assertSame(0, $code, $out);

        $db = new Database('sqlite:' . $this->path);
        $row = $db->one("SELECT role, is_admin, email_verified_at, approved_at FROM users WHERE email = 'cli@e.test'");
        $this->assertNotNull($row, 'user was created');
        $this->assertSame('admin', $row['role']);          // phase-1 iff invariant
        $this->assertSame(1, (int) $row['is_admin']);
        $this->assertNotNull($row['email_verified_at']);   // else the login gates dead-end a CLI account
        $this->assertNotNull($row['approved_at']);

        // The bootstrap admin can actually log in (no verify/approval gate).
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-cli-mail.log', 'from' => 'noreply@localhost'],
        ]);
        $res = (new TestClient($app))->post('/auth/attempt', ['email' => 'cli@e.test', 'password' => 'password123']);
        $this->assertSame(302, $res->status, 'CLI-created admin must pass the login gates: ' . substr($res->body, 0, 200));
    }

    public function test_rollback_undoes_the_last_batch_and_migrate_restores_it(): void
    {
        $this->kip('migrate');
        [$code, $out] = $this->kip('rollback'); // one batch spans app/migrations AND app/Features/*/migrations
        $this->assertSame(0, $code, $out);
        preg_match('/^Rolled back: (.+)$/m', $out, $m);
        $names = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
        $this->assertNotEmpty($names, 'rollback must name at least one migration, got: ' . $out);
        foreach ($names as $n) {
            $this->assertMatchesRegularExpression('/^\d{3}_/', $n, "not a migration name: {$n}");
        }

        // the fixture DB must end fully migrated for any later assertions
        [$code, $out] = $this->kip('migrate');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Ran: ', $out);
    }

    public function test_import_token_prints_token_and_hash_file_line(): void
    {
        [$code, $out] = $this->kip('import:token'); // CliTest's kip() returns [exit, stdout]
        $this->assertSame(0, $code);
        $this->assertStringContainsString('export-token.php', $out);
        $this->assertMatchesRegularExpression('/token: [a-f0-9]{64}/', $out);
        $this->assertMatchesRegularExpression("/<\?php return '[a-f0-9]{64}';/", $out);
        // the printed hash is the sha256 of the printed token
        preg_match('/token: ([a-f0-9]{64})/', $out, $m);
        preg_match("/return '([a-f0-9]{64})';/", $out, $h);
        $this->assertSame(hash('sha256', $m[1]), $h[1]);
    }

    public function test_import_token_is_random_per_invocation(): void
    {
        [, $a] = $this->kip('import:token');
        [, $b] = $this->kip('import:token');
        $this->assertNotSame($a, $b);
    }

    public function test_cache_clear_deletes_the_page_cache_db_and_its_sidecars(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-cli-cache-' . uniqid();
        mkdir($dir);
        $file = $dir . '/cache.sqlite';
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            file_put_contents($f, 'x');
        }
        [$code, $out] = $this->kip('cache:clear', 'sqlite:' . $file);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('framework page cache: ' . $file, $out,
            'both cache layers are named in the clear output');
        $this->assertStringContainsString('static layer:', $out);
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            $this->assertFileDoesNotExist($f, "{$f} must be gone");
        }
        // Absent files are a silent success: exit 0, no error text.
        [$code, $out] = $this->kip('cache:clear', 'sqlite:' . $file);
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('error', strtolower($out));
        // A memory cache has no file to clear: the framework layer is
        // skipped (no framework line) while the static layer still purges.
        [$code, $out] = $this->kip('cache:clear', 'sqlite::memory:');
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('framework page cache', $out);
        $this->assertStringContainsString('static layer:', $out);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_doctor_passes_end_to_end_and_names_a_broken_install(): void
    {
        $this->kip('migrate');
        // Healthy: fully migrated temp DB, throwaway cache and backup dirs.
        [$code, $out] = $this->kip('doctor', '', 'KIP_BACKUP_DIR=' . escapeshellarg($this->path . '-backups'));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('All checks passed', $out);
        // Machine-readable mode decodes and agrees with the human run.
        [$code, $json] = $this->kip('doctor --json', '', 'KIP_BACKUP_DIR=' . escapeshellarg($this->path . '-backups'));
        $this->assertSame(0, $code, $json);
        $this->assertTrue(json_decode($json, true)['ok']);
        // A static cache path nothing can create (a regular file in the way)
        // fails the named check with its fix and exits 1.
        $blocker = $this->path . '-blocker';
        file_put_contents($blocker, 'x');
        $cmd = sprintf('KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s KIP_BACKUP_DIR=%s %s %s doctor 2>&1',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($blocker . '/cache'),
            escapeshellarg($this->path . '-backups'),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'));
        exec($cmd, $sabotage, $scode);
        $sabotageOut = implode("\n", $sabotage);
        $this->assertSame(1, $scode, $sabotageOut);
        $this->assertStringContainsString('[FAIL] static cache writable', $sabotageOut);
        $this->assertStringContainsString('1 check(s) failed', $sabotageOut);
        @unlink($blocker);
    }

    public function test_version_states_app_framework_lock_and_php(): void
    {
        // The real lock: a dev-main git pin renders as version@hash12.
        [$code, $out] = $this->kip('version');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('kiption ' . \App\Version::VERSION, $out);
        $this->assertMatchesRegularExpression('/^kip\/framework \S+$/m', $out);
        $this->assertMatchesRegularExpression('/^php \d+\.\d+/m', $out);

        // Fixture locks drive the three pin renderings (KIP_COMPOSER_LOCK
        // points the arm at a throwaway file, the KIP_DB_DSN pattern).
        $dir = sys_get_temp_dir() . '/kiption-cli-version-' . uniqid();
        mkdir($dir);
        $lock = "{$dir}/composer.lock";
        $entry = static fn(array $overrides): string => json_encode(
            ['packages' => [array_merge([
                'name' => 'kip/framework', 'version' => 'dev-main',
                'source' => ['type' => 'git', 'url' => 'https://github.com/Hyperion360/kip.git',
                             'reference' => '2979b924372eaaec4f48b4fe11337e3362cdf45b'],
            ], $overrides)], 'packages-dev' => []]);
        // git dev pin
        file_put_contents($lock, $entry([]));
        [, $out] = $this->kip('version', '', 'KIP_COMPOSER_LOCK=' . escapeshellarg($lock));
        $this->assertStringContainsString('kip/framework dev-main@2979b924372e', $out);
        // path-repo pin, hiding in packages-dev: version + path, both sections are scanned
        file_put_contents($lock, json_encode(['packages' => [], 'packages-dev' => [[
            'name' => 'kip/framework', 'version' => '0.5.x-dev',
            'dist' => ['type' => 'path', 'url' => '/tmp/MVC-Lite']]]]));
        [, $out] = $this->kip('version', '', 'KIP_COMPOSER_LOCK=' . escapeshellarg($lock));
        $this->assertStringContainsString('kip/framework 0.5.x-dev /tmp/MVC-Lite', $out);
        // tag pin prints the tag
        file_put_contents($lock, $entry(['version' => '0.4.0', 'source' => [
            'type' => 'git', 'url' => 'https://github.com/Hyperion360/kip.git', 'reference' => 'aaaa1111bbbb2222cccc3333dddd4444eeee5555']]));
        [, $out] = $this->kip('version', '', 'KIP_COMPOSER_LOCK=' . escapeshellarg($lock));
        $this->assertStringContainsString('kip/framework 0.4.0', $out);
        $this->assertStringNotContainsString('kip/framework 0.4.0@', $out);

        // --check against an unreachable remote stays honest: a labeled
        // unavailable line, exit 0 (network is optional, never a guess).
        [$code, $out] = $this->kip('version --check', '',
            'KIP_COMPOSER_LOCK=' . escapeshellarg($lock) . ' KIP_FRAMEWORK_REMOTE=' . escapeshellarg($dir . '/no-such-remote'));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('upstream check unavailable', $out);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    // ------------------------------------------------------------------
    // import:epub. The shared kip() folds stderr into stdout with 2>&1;
    // the import refusals print to STDERR, so these cases run the command
    // through a local wrapper that captures the two streams apart.
    // ------------------------------------------------------------------

    /** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
    private function kipSplit(string $args): array
    {
        $staticDir = $this->path . '-static';
        if (!is_dir($staticDir)) { mkdir($staticDir); }
        $outFile = $this->path . '-out';
        $errFile = $this->path . '-err';
        $cmd = sprintf('KIP_DB_DSN=%s KIP_STATIC_CACHE_DIR=%s %s %s %s > %s 2> %s',
            escapeshellarg('sqlite:' . $this->path),
            escapeshellarg($staticDir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/bin/kip'),
            $args,
            escapeshellarg($outFile),
            escapeshellarg($errFile));
        exec($cmd, $ignored, $code);
        return [(int) $code, (string) file_get_contents($outFile), (string) file_get_contents($errFile)];
    }

    /** A throwaway directory for the .epub fixtures, removed on teardown. */
    private function fixturesDir(): string
    {
        if ($this->fixtures === '') {
            $this->fixtures = sys_get_temp_dir() . '/kiption-cli-fx-' . uniqid('', true);
            mkdir($this->fixtures, 0775, true);
        }
        return $this->fixtures;
    }

    /** A minimal importable EPUB at fixtures/<name>: container, OPF
     *  (title, creator, no cover convention), N one-paragraph chapters.
     *  Returns the absolute path, the EpubImportTest fixture idiom. */
    private function epub(string $name, string $title, string $creator = 'cli-author', int $chapters = 3): string
    {
        $items = $refs = [];
        $path = $this->fixturesDir() . '/' . $name;
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail("fixture zip {$name} failed to open for writing");
        }
        for ($n = 1; $n <= $chapters; $n++) {
            $items[] = sprintf('<item id="ch%d" href="Text/ch%d.xhtml" media-type="application/xhtml+xml"/>', $n, $n);
            $refs[] = sprintf('<itemref idref="ch%d"/>', $n);
            $zip->addFromString(sprintf('OEBPS/Text/ch%d.xhtml', $n),
                '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>ch' . $n . '</title></head>'
                . '<body><h2>ch' . $n . '</h2><p>Prose for chapter ' . $n . '.</p></body></html>');
        }
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml',
            '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            . '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
        $zip->addFromString('OEBPS/content.opf',
            '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>' . $title . '</dc:title><dc:creator>' . $creator . '</dc:creator>'
            . '<dc:description>A CLI fixture.</dc:description><dc:language>en</dc:language></metadata>'
            . '<manifest>' . implode('', $items) . '</manifest><spine>' . implode('', $refs) . '</spine></package>');
        $zip->close();
        return $path;
    }

    /** A member with a known penname, the EpubImportTest INSERT idiom
     *  against the same throwaway DB the CLI points at. */
    private function member(string $penname): int
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at) VALUES (?, ?, ?, ?, ?)",
            [$penname . '@e.test', password_hash('x1234567', PASSWORD_DEFAULT), $penname, date('c'), date('c')]);
        return (int) $db->lastInsertId();
    }

    private function rows(string $sql): int
    {
        return (int) (new Database('sqlite:' . $this->path))->one($sql)['c'];
    }

    /** The common preamble: schema, blank taxonomy, one author member. */
    private function importSetup(): int
    {
        $this->kip('migrate');
        $this->kip('db:blank');
        return $this->member('cli-author');
    }

    public function test_import_epub_commit_imports_a_file_and_prints_the_report(): void
    {
        $authorId = $this->importSetup();
        $epub = $this->epub('book.epub', 'Cli Title');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author-id=' . $authorId);
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertMatchesRegularExpression('/^Imported: Cli Title \(3 chapters, \d+ words\)$/m', $out);
        $this->assertStringNotContainsString('Would import', $out);
        $this->assertSame('', $err, 'a clean import prints nothing on stderr');
        $db = new Database('sqlite:' . $this->path);
        $story = $db->one("SELECT id, title, author_id FROM stories WHERE title = 'Cli Title'");
        $this->assertNotNull($story, 'the story row landed');
        $this->assertSame($authorId, (int) $story['author_id']);
        $this->assertSame(3, $this->rows('SELECT COUNT(*) c FROM chapters WHERE story_id = ' . (int) $story['id']));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) c FROM epub_imports'));
    }

    public function test_import_epub_imports_a_directory_sorted_with_a_summary(): void
    {
        $authorId = $this->importSetup();
        $this->epub('b-second.epub', 'Beta Book');
        $this->epub('a-first.epub', 'Alpha Book');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($this->fixturesDir()) . ' --commit --author-id=' . $authorId);
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertMatchesRegularExpression('/^Imported: Alpha Book \(3 chapters, \d+ words\)$/m', $out);
        $this->assertMatchesRegularExpression('/^Imported: Beta Book \(3 chapters, \d+ words\)$/m', $out);
        $this->assertLessThan(strpos($out, 'Imported: Beta Book'), strpos($out, 'Imported: Alpha Book'),
            'the directory walks in filename order, not directory order');
        $this->assertMatchesRegularExpression('/^2 imported, 0 skipped, 0 failed$/m', $out);
        $this->assertSame(2, $this->rows('SELECT COUNT(*) c FROM stories'));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) c FROM stories WHERE title = 'Alpha Book'"));
    }

    public function test_import_epub_dry_run_prints_and_persists_nothing(): void
    {
        $this->importSetup();
        $epub = $this->epub('dry.epub', 'Cli Title');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --dry-run --author=cli-author');
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertMatchesRegularExpression('/^Would import: Cli Title \(3 chapters, \d+ words\)$/m', $out);
        $this->assertStringNotContainsString('Imported:', $out);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'), 'a dry run writes no story');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM chapters'));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM epub_imports'), 'a dry run writes no ledger row');
    }

    public function test_import_epub_unknown_penname_refuses_on_stderr_before_any_import(): void
    {
        $this->importSetup();
        $epub = $this->epub('ghost.epub', 'Cli Title');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author=nope');
        $this->assertSame(1, $code);
        $this->assertStringContainsString("no member carries the penname 'nope'", $err, 'the refusal reaches STDERR');
        $this->assertSame('', $out, 'a refusal prints no success lines');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'));
    }

    /** A nonexistent --author-id refuses once, up front: the importer's own
     *  id check fires per file, so a directory run would otherwise print one
     *  Failed block per book (the penname path already fast-fails here). */
    public function test_import_epub_unknown_author_id_refuses_once_before_any_import(): void
    {
        $this->importSetup();
        $this->epub('lonely.epub', 'Ghost Id');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($this->fixturesDir()) . ' --commit --author-id=999999');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('no member carries author id 999999', $err, 'the refusal names the id on STDERR');
        $this->assertSame('', $out, 'a refusal prints no success lines');
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'));
    }

    public function test_import_epub_rejects_both_author_flags_as_a_usage_error(): void
    {
        $this->importSetup();
        $epub = $this->epub('book.epub', 'Cli Title');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author=a --author-id=1');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip import:epub', $err);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'));
    }

    public function test_import_epub_rejects_dry_run_and_commit_together_as_a_usage_error(): void
    {
        $this->importSetup();
        $epub = $this->epub('book.epub', 'Cli Title');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --dry-run --commit');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip import:epub', $err);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'));
    }

    public function test_import_epub_rejects_empty_author_values_as_a_usage_error(): void
    {
        $this->importSetup();
        $epub = $this->epub('book.epub', 'Cli Title');
        [$code, , $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author=');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip import:epub', $err);
        [$code, , $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author-id=');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage: kip import:epub', $err);
        $this->assertSame(0, $this->rows('SELECT COUNT(*) c FROM stories'));
    }

    /** The mid-batch corruption case: a good book followed by a corrupt one
     *  must report each file as itself (the failed row can never be the
     *  previous file's) and sink the run's exit code. */
    public function test_import_epub_mixed_batch_reports_each_file_and_fails_the_run(): void
    {
        $authorId = $this->importSetup();
        $this->epub('1-good.epub', 'Good Book');
        file_put_contents($this->fixturesDir() . '/2-corrupt.epub', 'not a zip archive at all');
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($this->fixturesDir()) . ' --commit --author-id=' . $authorId);
        $this->assertSame(1, $code, "stdout: {$out}\nstderr: {$err}");
        $this->assertSame(1, substr_count($out, 'Imported:'), 'the good file imports once: ' . $out);
        $this->assertMatchesRegularExpression('/^Imported: Good Book \(3 chapters, \d+ words\)$/m', $out);
        $this->assertMatchesRegularExpression('/^Failed: .*2-corrupt\.epub$/m', $err);
        $this->assertMatchesRegularExpression('/not a readable zip archive/', $err, 'the failure reason rides the Failed block');
        $this->assertMatchesRegularExpression('/^1 imported, 0 skipped, 1 failed$/m', $out);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) c FROM stories'));
        $this->assertSame(3, $this->rows('SELECT COUNT(*) c FROM chapters'));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) c FROM epub_imports'));
    }



    /** Finding: the digest check was commit-only, so a dry run promised
     *  "Would import" for a file commit would skip. The ledger lookup runs
     *  in both modes and the dry run reports Would skip, writing nothing. */
    public function test_import_epub_dry_run_reports_would_skip_for_an_already_imported_file(): void
    {
        $authorId = $this->importSetup();
        $epub = $this->epub('again.epub', 'Repeat Book');
        [$code, $out] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --commit --author-id=' . $authorId);
        $this->assertSame(0, $code, $out);
        [$code, $out, $err] = $this->kipSplit('import:epub ' . escapeshellarg($epub) . ' --dry-run --author-id=' . $authorId);
        $this->assertSame(0, $code, "stdout: {$out}\nstderr: {$err}");
        // the skip fires before any conversion work, so the counts stay 0:
        // nothing beyond the digest was read
        $this->assertMatchesRegularExpression('/^Would skip: Repeat Book \(0 chapters, 0 words\)$/m', $out);
        $this->assertMatchesRegularExpression('/already imported as story \d+/', $out);
        $this->assertMatchesRegularExpression('/^0 would import, 1 skipped, 0 failed$/m', $out);
        $this->assertStringNotContainsString('Would import:', $out);
        $this->assertSame(1, $this->rows('SELECT COUNT(*) c FROM stories'), 'the dry run writes no story');
        $this->assertSame(3, $this->rows('SELECT COUNT(*) c FROM chapters'));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) c FROM epub_imports'), 'the dry run writes no ledger row');
    }

}
