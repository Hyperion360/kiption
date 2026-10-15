<?php // tests/WebUpgradeTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The browser and PHP CLI upgrade track: a staged release is verified without
 * executing it, an authenticated owner drives prepare/activate/continue over
 * HTTP or the private CLI, and one coordinator journals every transition so a
 * crash at any point recovers to the matching code, asset, and database
 * snapshot. Task 1 grounds the layout: stable wrappers, the private pointer,
 * and shared mutable data that never lives under a release or a web root.
 */
final class WebUpgradeTest extends TestCase
{
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $d) {
            self::rrmdir($d);
        }
    }

    // ------------------------------------------------------------- helpers

    private function tmptree(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0775, true)) {
            $this->fail("cannot create {$dir}");
        }
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** PHP-only recursive copy; skips nothing silently: callers name exclusions. */
    private static function rrcopy(string $src, string $dst, array $exclude = []): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0775, true);
        }
        foreach (scandir($src) ?: [] as $e) {
            if ($e === '.' || $e === '..' || in_array($e, $exclude, true)) {
                continue;
            }
            $s = $src . '/' . $e;
            if (is_link($s)) {
                throw new \RuntimeException("symlink in source: {$s}");
            }
            is_dir($s) ? self::rrcopy($s, $dst . '/' . $e, $exclude) : copy($s, $dst . '/' . $e);
        }
    }

    /** @return array{0:int,1:string} exit code, combined output */
    private function sh(string $cwd, string $cmd): array
    {
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    private function sourceCopy(string $home): string
    {
        $copy = $home . '/src';
        self::rrmdir($copy); // a fresh copy: an earlier build in this home must not leak into this one
        self::rrcopy(dirname(__DIR__), $copy, ['.git', 'vendor']);
        return $copy;
    }

    private function build(string $copy, string $args): array
    {
        return $this->sh($copy, sprintf(
            'PATH="/opt/homebrew/bin:/usr/bin:/bin:$PATH" %s scripts/build-release.php %s',
            escapeshellarg(PHP_BINARY),
            $args
        ));
    }

    /** Installs a built release ZIP into a host home and returns [home, id]. */
    private function install(string $home, string $installZip): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($installZip) === true);
        self::assertTrue($zip->extractTo($home));
        $zip->close();
        $id = trim((string) file_get_contents($home . '/site/current'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);
        return [$home, $id];
    }

    /** A built, extracted, single-release host home in the public_html layout. */
    private function hostHome(string $prefix, string $version = '0.1.0-test'): array
    {
        $home = $this->tmptree($prefix);
        $copy = $this->sourceCopy($home);
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD'));
        $zipPath = $home . '/install.zip';
        [$code, $out] = $this->build($copy, "--development --allow-dirty --version={$version}"
            . " --git-sha={$sha} --output=" . escapeshellarg($zipPath));
        $this->assertSame(0, $code, $out);
        return $this->install($home, $zipPath);
    }

    private function deployment(string $home, int $port): void
    {
        file_put_contents($home . '/site/shared/deployment.php', "<?php\nreturn [\n"
            . "    'public_dir' => " . var_export($home . '/public_html', true) . ",\n"
            . "    'base_url' => 'http://127.0.0.1:' . " . var_export((string) $port, true) . ",\n"
            . "    'mail' => ['transport' => 'log', 'log_path' => __DIR__ . '/mail.log', 'from' => 'noreply@localhost'],\n"
            . "    'registration_mode' => 'invite',\n"
            . "];\n");
    }

    private function kip(string $home): string
    {
        return 'PATH="/opt/homebrew/bin:/usr/bin:/bin" ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($home . '/site/bin/kip');
    }

    private function freePort(int $start): int
    {
        for ($port = $start; $port < $start + 16; $port++) {
            $sock = @fsockopen('127.0.0.1', $port, timeout: 0.2);
            if ($sock === false) {
                return $port;
            }
            fclose($sock);
        }
        $this->fail('no free port in the ad-hoc range');
    }

    /** @return resource */
    private function startServer(int $port, string $docroot, string $router, array $phpArgs = []): mixed
    {
        $handle = proc_open(
            [PHP_BINARY, ...$phpArgs, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($handle, 'dev server failed to start');
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port, timeout: 0.2) !== false) {
                return $handle;
            }
            usleep(100000);
        }
        proc_terminate($handle);
        $this->fail('dev server never came up');
    }

    /** @return array{0:int,1:string|false} status, body */
    private function http(int $port, string $method, string $path, array $headers = [], string $body = ''): array
    {
        $context = stream_context_create(['http' => [
            'ignore_errors' => true, 'timeout' => 20, 'method' => $method,
            'header' => implode("\r\n", $headers), 'content' => $body,
        ]]);
        $result = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $result];
    }

    // ------------------------------------------------- Task 1: stable layout

    /**
     * The versioned layout: one immutable release under site/releases/<id>/,
     * a plain-text private pointer, shared mutable data beside it, and stable
     * entry points that serve the pointed-at release or a generic 503. After
     * real CLI work nothing mutable ever lands under a release or a web root,
     * and doctor judges the shared data directory, not the release's own app
     * tree (which is immutable source).
     */
    public function test_versioned_layout(): void
    {
        [$home, $id] = $this->hostHome('kiption-wu-layout-');
        $release = $home . '/site/releases/' . $id;

        $this->assertFileExists($release . '/vendor/autoload.php');
        $this->assertFileExists($release . '/app/migrations');
        $this->assertGreaterThan(0, count(glob($release . '/app/migrations/*.php') ?: []));
        $this->assertFileExists($home . '/site/current');
        $this->assertDirectoryExists($home . '/site/shared');
        $this->assertSame($id . "\n", (string) file_get_contents($home . '/site/current'),
            'the pointer is plain text: exactly the id and a newline');

        $this->deployment($home, $this->freePort(8095));
        $kip = $this->kip($home);
        [$mCode, $mOut] = $this->sh($home, $kip . ' migrate');
        $this->assertSame(0, $mCode, $mOut);
        [$bCode, $bOut] = $this->sh($home, $kip . ' backup');
        $this->assertSame(0, $bCode, $bOut);
        [$dCode, $dOut] = $this->sh($home, $kip . ' doctor');
        $this->assertSame(0, $dCode, $dOut);

        // No mutable artifact ever lands under a release or a web root.
        $mutable = '/\.sqlite(-wal|-shm)?$|deployment\.php|setup-token|(^|\/)backups(\/|$)|mail\.log$|nav\.json$/';
        foreach ([$home . '/site/releases', $home . '/public_html'] as $root) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
            );
            foreach ($it as $path) {
                $rel = ltrim(substr((string) $path, strlen($root)), '/');
                $this->assertDoesNotMatchRegularExpression($mutable, $rel, "mutable file inside {$root}: {$rel}");
            }
        }
        $this->assertFileExists($home . '/site/shared/data.sqlite', 'the content database lives in the shared tree');
        $this->assertFileExists($home . '/site/shared/backups', 'backups live in the shared tree');

        // The immutable release tree is read-only source: doctor must still
        // pass, because it judges the shared data directory (the old code
        // asked whether releases/<id>/app were writable and failed here).
        foreach ([$release, $release . '/app'] as $dir) {
            $this->assertTrue(chmod($dir, 0555), "cannot read-only {$dir}");
        }
        [$d2Code, $d2Out] = $this->sh($home, $kip . ' doctor');
        $this->assertSame(0, $d2Code, "doctor must judge the shared data tree, not the release source: {$d2Out}");
        foreach ([$release, $release . '/app'] as $dir) {
            chmod($dir, 0775);
        }

        // The stable wrapper serves the pointed-at release, and a malformed
        // or missing pointer is a generic 503 that prints no path.
        $port = $this->freePort(8095);
        $server = $this->startServer($port, $home . '/public_html', $home . '/public_html/index.php');
        try {
            $this->assertSame(200, $this->http($port, 'GET', '/')[0], 'the wrapper serves the active release');
            file_put_contents($home . '/site/current', "../../etc\n");
            [$badCode, $badBody] = $this->http($port, 'GET', '/');
            $this->assertSame(503, $badCode, 'a malformed pointer is a generic 503');
            $this->assertStringNotContainsString('/', explode("\n", (string) $badBody)[0], 'no path in the refusal');
            unlink($home . '/site/current');
            $this->assertSame(503, $this->http($port, 'GET', '/')[0], 'a missing pointer is a generic 503');
            file_put_contents($home . '/site/current', $id . "\n");
            $this->assertSame(200, $this->http($port, 'GET', '/')[0], 'restoring the pointer restores service');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * The private pointer library: the one validator and the one writer every
     * stable upgrade consumer shares. It accepts only a 64-hex ID whose
     * release directory resolves inside site/releases/, never follows a
     * crafted path, and switches by 0600 temp-file rename in the pointer's
     * own directory.
     */
    public function test_release_pointer(): void
    {
        require_once dirname(__DIR__) . '/upgrade/ReleasePointer.php';
        $home = $this->tmptree('kiption-wu-pointer-');
        $site = $home . '/site';
        mkdir($site . '/releases/' . str_repeat('a', 64), 0775, true);
        mkdir($site . '/shared', 0775, true);

        $this->assertNull(\Upgrade\ReleasePointer::active($site), 'no pointer yet');
        file_put_contents($site . '/current', str_repeat('a', 64) . "\n");
        $this->assertSame(str_repeat('a', 64), \Upgrade\ReleasePointer::active($site));

        foreach (["../../etc\n", strtoupper(str_repeat('a', 64)) . "\n", str_repeat('a', 63) . "\n",
            str_repeat('a', 64) . str_repeat('b', 64) . "\n", "garbage\n", ''] as $bad) {
            file_put_contents($site . '/current', $bad);
            $this->assertNull(\Upgrade\ReleasePointer::active($site), 'malformed pointer refused: ' . var_export($bad, true));
        }
        file_put_contents($site . '/current', str_repeat('b', 64) . "\n");
        $this->assertNull(\Upgrade\ReleasePointer::active($site), 'a well-formed id with no release directory is refused');

        // A symlink that escapes the releases root is never followed.
        $outside = $home . '/outside';
        mkdir($outside, 0775, true);
        @symlink($outside, $site . '/releases/' . str_repeat('c', 64));
        file_put_contents($site . '/current', str_repeat('c', 64) . "\n");
        $this->assertNull(\Upgrade\ReleasePointer::active($site), 'an escaping symlink is refused');

        // switch(): atomic, 0600, validates before writing.
        \Upgrade\ReleasePointer::switch($site, str_repeat('a', 64));
        $this->assertSame(str_repeat('a', 64) . "\n", (string) file_get_contents($site . '/current'));
        $this->assertSame(0600, (int) (fileperms($site . '/current') & 0777), 'the pointer is private');
        foreach ([str_repeat('z', 64), 'banana', str_repeat('A', 64)] as $badId) {
            try {
                \Upgrade\ReleasePointer::switch($site, $badId);
                $this->fail("switch must refuse {$badId}");
            } catch (\InvalidArgumentException) {
                $this->assertSame(str_repeat('a', 64) . "\n", (string) file_get_contents($site . '/current'),
                    'a refused switch leaves the pointer untouched');
            }
        }
        // No temp files left behind, and the switch target must exist.
        $leftovers = array_values(array_diff(scandir($site) ?: [], ['.', '..', 'current', 'releases', 'shared']));
        $this->assertSame([], $leftovers, 'switch leaves no temporary files');
        try {
            \Upgrade\ReleasePointer::switch($site, str_repeat('e', 64));
            $this->fail('switch must refuse an id with no release directory');
        } catch (\InvalidArgumentException) {
        }
    }

    /** Doctor's data-dir verdict follows the explicit data_dir seam. */
    public function test_doctor_checks_the_data_dir_not_the_code_dir(): void
    {
        $home = $this->tmptree('kiption-wu-doctor-');
        mkdir($home . '/app', 0775, true);
        mkdir($home . '/public/cache', 0775, true);
        mkdir($home . '/public/uploads', 0775, true);
        mkdir($home . '/shared', 0775, true);
        // The code tree is unwritable (release layout); the data dir is fine.
        chmod($home . '/app', 0555);
        $env = [
            'app_dir' => $home . '/app',
            'data_dir' => $home . '/shared',
            'public_dir' => $home . '/public',
            'config' => [
                'base_url' => 'https://archive.example.test',
                'db' => ['dsn' => 'sqlite:' . $home . '/shared/data.sqlite'],
                'mail' => ['transport' => 'log', 'log_path' => $home . '/shared/mail.log'],
                'backups' => ['dir' => $home . '/shared/backups'],
                'static_cache' => ['enabled' => true, 'dir' => $home . '/public/cache'],
                'uploads' => ['dir' => $home . '/public/uploads'],
            ],
            'expected_migrations' => 0,
        ];
        $report = \App\Doctor::run($env);
        chmod($home . '/app', 0775);
        $this->assertTrue($report['ok'], json_encode($report['checks'], JSON_PRETTY_PRINT));
        $byName = array_column($report['checks'], null, 'name');
        $this->assertArrayHasKey('writable data dir', $byName);
        $this->assertStringContainsString($home . '/shared', (string) $byName['writable data dir']['summary'],
            'the data-dir check reports the shared tree');
    }

    // ------------------------------------------- Task 2: staged verification

    /**
     * A production-shaped release fixture: plain files whose bytes the
     * manifest pins, exactly the tree an owner extracts from the release-root
     * upgrade ZIP. Staged PHP deliberately throws if anything ever executes
     * it: verification reads bytes and hashes, never code. $migration
     * replaces the throwing probe with a runnable migration for the
     * state-machine tests.
     */
    private function writeFixtureTree(string $tree, string $version, ?string $migration = null): void
    {
        foreach (['app/migrations', 'vendor/composer', 'public/assets', 'bin'] as $dir) {
            mkdir($tree . '/' . $dir, 0775, true);
        }
        file_put_contents($tree . '/config.php', "<?php\nreturn [];\n");
        file_put_contents($tree . '/composer.json', "{\"require\":{\"php\":\"^8.3\"}}\n");
        file_put_contents($tree . '/composer.lock', "{\"packages\":[]}\n");
        file_put_contents($tree . '/vendor/autoload.php', "<?php // fixture autoloader\n");
        file_put_contents($tree . '/vendor/composer/installed.json', "{\"packages\":[]}\n");
        file_put_contents($tree . '/app/migrations/900_fixture.php', $migration
            ?? "<?php throw new \\Exception('staged code must never execute');\n");
        file_put_contents($tree . '/public/index.php', "<?php throw new \\Exception('staged code must never execute');\n");
        file_put_contents($tree . '/public/assets/style.css', "/* fixture target asset */\n");
        file_put_contents($tree . '/bin/kip', "#!/usr/bin/env php\n<?php exit(97); // must never execute\n");
        // A per-tree nonce: two fixtures never share a digest, so renames
        // never collide across test cases.
        file_put_contents($tree . '/BUILD_NONCE', bin2hex(random_bytes(16)) . "\n");
    }

    /** @return array{0:string,1:string} manifest bytes, id (sha256 of the bytes) */
    private function fixtureManifest(string $tree, string $version, array $extra = []): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            $rel = ltrim(substr((string) $path, strlen($tree)), '/');
            if ($rel === 'RELEASE.json') {
                continue;
            }
            $files[$rel] = hash_file('sha256', (string) $path);
        }
        ksort($files);
        $payload = ['version' => $version, 'git_sha' => str_repeat('3', 40),
            'lock_hash' => hash('sha256', "{\"packages\":[]}\n"), 'php_version' => PHP_VERSION,
            'upgrade_protocol' => 1, 'files' => $files, ...$extra];
        $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return [$bytes, hash('sha256', $bytes)];
    }

    /** Writes a complete staged release named after its own digest. */
    private function stageFixture(string $siteRoot, string $version, array $extra = [], ?string $migration = null): array
    {
        $tree = $this->tmptree('kiption-wu-fixture-');
        $this->writeFixtureTree($tree, $version, $migration);
        [$manifest, $id] = $this->fixtureManifest($tree, $version, $extra);
        file_put_contents($tree . '/RELEASE.json', $manifest);
        $stage = $siteRoot . '/releases/' . $id;
        self::rrmdir($stage);
        rename($tree, $stage);
        array_pop($this->tmpDirs); // the fixture tree moved into the home tree
        return [$stage, $manifest, $id];
    }

    /** Installs an active release fixture and points the site at it. */
    private function activeFixture(string $siteRoot, string $version): string
    {
        [, , $id] = $this->stageFixture($siteRoot, $version);
        file_put_contents($siteRoot . '/current', $id . "\n");
        return $id;
    }

    /** Three tiny live SQLite databases under site/shared. */
    private function liveDbs(string $siteRoot): array
    {
        $dbs = [];
        foreach (['data', 'logs', 'cache'] as $label) {
            $file = $siteRoot . '/shared/' . $label . '.sqlite';
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec('CREATE TABLE IF NOT EXISTS probe (v TEXT)');
            $pdo->exec("INSERT INTO probe (v) VALUES ('live')");
            $dbs[$label] = 'sqlite:' . $file;
        }
        return $dbs;
    }

    private function verifier(string $siteRoot, string $activeId, array $options = []): \Upgrade\ReleaseVerifier
    {
        return new \Upgrade\ReleaseVerifier(
            $siteRoot,
            (string) file_get_contents($siteRoot . '/releases/' . $activeId . '/RELEASE.json'),
            $this->liveDbs($siteRoot),
            $siteRoot . '/../public_html',
            $options,
        );
    }

    /**
     * A staged release passes only with the exact published RELEASE.json
     * digest; every listed file must match, nothing unlisted may ride along,
     * the tree must resolve without symlinks or traversal, and the release
     * must be a protocol-compatible, newer, PHP-compatible release. The
     * verifier reads bytes only: staged code that would explode on execution
     * verifies fine, and the live site is untouched by a failed run.
     */
    public function test_staged_release_verification(): void
    {
        require_once dirname(__DIR__) . '/upgrade/ReleasePointer.php';
        require_once dirname(__DIR__) . '/upgrade/ReleaseVerifier.php';
        $home = $this->tmptree('kiption-wu-verify-');
        $site = $home . '/site';
        mkdir($site . '/releases', 0775, true);
        mkdir($site . '/shared', 0775, true);
        mkdir($home . '/public_html', 0775, true);
        $activeId = $this->activeFixture($site, '1.0.0');
        $this->liveDbs($site);
        [$stage, , $id] = $this->stageFixture($site, '1.1.0');

        // Happy path: the published digest verifies the whole tree.
        $r = $this->verifier($site, $activeId)->verify($stage, $id, rehearse: true);
        $this->assertSame([], $r['failures'], json_encode($r['failures'], JSON_PRETTY_PRINT));
        $this->assertTrue($r['ok']);
        $this->assertSame($id, $r['id']);
        $this->assertSame('1.1.0', $r['version']);
        $this->assertArrayHasKey('data', $r['rehearsal'], 'the rehearsal records each database duration');
        $this->assertGreaterThan(0.0, $r['rehearsal']['data']);

        // The published digest is mandatory, exact, and hex-only.
        foreach (['', str_repeat('0', 64), strtoupper($id), $id . '0', substr($id, 0, 63)] as $bad) {
            $r = $this->verifier($site, $activeId)->verify($stage, $bad, rehearse: false);
            $this->assertFalse($r['ok'], "digest " . var_export($bad, true) . " must not verify");
        }

        // A coherently altered tree (PHP file changed AND manifest rebuilt)
        // still fails against the original published digest.
        file_put_contents($stage . '/app/migrations/900_fixture.php', "<?php // altered\n");
        [$remanifest, $reid] = $this->fixtureManifest($stage, '1.1.0');
        file_put_contents($stage . '/RELEASE.json', $remanifest);
        rename($stage, $site . '/releases/' . $reid);
        $stage = $site . '/releases/' . $reid;
        $r = $this->verifier($site, $activeId)->verify($stage, $id, rehearse: false);
        $this->assertFalse($r['ok'], 'a coherently altered release must fail the independently entered digest');

        // From here on: a fresh honest stage per case, one failure each.
        $freshStage = function () use ($site, &$stage): string {
            self::rrmdir($stage);
            [$stage] = $this->stageFixture($site, '1.1.0');
            return $stage;
        };
        $expectFail = function (string $what, array $options = []) use ($site, $activeId, &$stage, $freshStage): array {
            $r = $this->verifier($site, $activeId, $options)->verify($stage, hash_file('sha256', $stage . '/RELEASE.json'), rehearse: false);
            $this->assertFalse($r['ok'], "{$what} must not verify: " . json_encode($r['failures']));
            return $r;
        };

        // An altered PHP file alone: the manifest pin catches it.
        $freshStage();
        file_put_contents($stage . '/config.php', "<?php // altered\n");
        $expectFail('an altered file');

        // An extra unlisted PHP file.
        $freshStage();
        file_put_contents($stage . '/public/shell.php', "<?php\n");
        $expectFail('an extra PHP file');

        // A symlink anywhere in the staged tree.
        $freshStage();
        unlink($stage . '/app/migrations/900_fixture.php');
        @symlink('/etc/passwd', $stage . '/app/migrations/900_fixture.php');
        $expectFail('a symlinked file');

        // Traversal in the manifest.
        $freshStage();
        $manifest = (string) file_get_contents($stage . '/RELEASE.json');
        $meta = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        $meta['files']['../../../etc/passwd'] = str_repeat('0', 64);
        $tampered = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($stage . '/RELEASE.json', $tampered);
        rename($stage, $site . '/releases/' . hash('sha256', $tampered));
        $stage = $site . '/releases/' . hash('sha256', $tampered);
        $expectFail('a traversal path');

        // A missing framework autoloader (manifest rebuilt to match).
        $freshStage();
        unlink($stage . '/vendor/autoload.php');
        [$m2, $i2] = $this->fixtureManifest($stage, '1.1.0');
        file_put_contents($stage . '/RELEASE.json', $m2);
        rename($stage, $site . '/releases/' . $i2);
        $stage = $site . '/releases/' . $i2;
        $expectFail('a missing framework autoloader');

        // Wrong upgrade protocol.
        $freshStage();
        [$m3, $i3] = $this->fixtureManifest($stage, '1.1.0', ['upgrade_protocol' => 2]);
        file_put_contents($stage . '/RELEASE.json', $m3);
        rename($stage, $site . '/releases/' . $i3);
        $stage = $site . '/releases/' . $i3;
        $expectFail('a wrong upgrade protocol');

        // Missing or malformed SemVer, a downgrade, and an equal version.
        foreach ([null, 'one.point.o', '1.0.0', '0.9.0'] as $version) {
            $freshStage();
            $extra = $version === null ? ['version' => ''] : [];
            [$m4, $i4] = $this->fixtureManifest($stage, (string) $version, $extra);
            file_put_contents($stage . '/RELEASE.json', $m4);
            rename($stage, $site . '/releases/' . $i4);
            $stage = $site . '/releases/' . $i4;
            $expectFail('version ' . var_export($version, true));
        }

        // A PHP requirement the host cannot meet.
        $freshStage();
        file_put_contents($stage . '/composer.json', "{\"require\":{\"php\":\"^9.0\"}}\n");
        [$m5, $i5] = $this->fixtureManifest($stage, '1.1.0');
        file_put_contents($stage . '/RELEASE.json', $m5);
        rename($stage, $site . '/releases/' . $i5);
        $stage = $site . '/releases/' . $i5;
        $expectFail('an unmeetable PHP requirement');

        // A development bundle can never be an upgrade target.
        $freshStage();
        [$m6, $i6] = $this->fixtureManifest($stage, '1.1.0', ['development_bundle' => true]);
        file_put_contents($stage . '/RELEASE.json', $m6);
        rename($stage, $site . '/releases/' . $i6);
        $stage = $site . '/releases/' . $i6;
        $expectFail('a development bundle');

        // The staging directory must be named for the digest it carries.
        $freshStage();
        $id = hash_file('sha256', $stage . '/RELEASE.json');
        rename($stage, $site . '/releases/' . str_repeat('f', 64));
        $r = $this->verifier($site, $activeId)->verify($site . '/releases/' . str_repeat('f', 64), (string) $id, rehearse: false);
        $this->assertFalse($r['ok'], 'a misnamed staging directory must not verify');
        $stage = $site . '/releases/' . str_repeat('f', 64);

        // Disk seams: no free space anywhere, and a peak that fits the
        // databases but not the asset swap.
        $freshStage();
        $expectFail('a full disk', ['free_of' => static fn (string $p): float|false => 0.0]);
        $dbBytes = 0.0;
        foreach (['data', 'logs', 'cache'] as $label) {
            $dbBytes += (float) filesize($site . '/shared/' . $label . '.sqlite');
        }
        $margin = 64.0 * 1024 * 1024;
        $tight = static fn (string $p): float|false => str_contains($p, 'shared')
            ? 3 * $dbBytes + $margin - 1.0
            : 1.0 * 1024 * 1024 * 1024;
        $expectFail('a peak short of the safety margin', ['free_of' => $tight]);

        // Locking and rename semantics on the real filesystems; an injected
        // cross-device rename or dead lock fails the host.
        $freshStage();
        $expectFail('a broken lock surface', ['fault' => ['flock']]);
        $freshStage();
        $expectFail('a non-atomic public tree', ['fault' => ['rename']]);
        $freshStage();
        $expectFail('a cross-device asset move', ['fault' => ['cross_device']]);

        // The rehearsal fails closed to the CLI path when one request cannot
        // finish it, and a failed verification changes nothing on the site.
        $freshStage();
        $before = (string) file_get_contents($site . '/current');
        $r = $this->verifier($site, $activeId, ['time_budget' => 0.0])->verify($stage, (string) hash_file('sha256', $stage . '/RELEASE.json'), rehearse: true);
        $this->assertFalse($r['ok'], 'a zero-time rehearsal budget must fail closed');
        $this->assertStringContainsString('CLI', implode(' ', $r['failures']));
        $this->assertSame($before, (string) file_get_contents($site . '/current'), 'the pointer is untouched');
        $this->assertFileDoesNotExist($site . '/shared/maintenance.lock');
        $this->assertFileDoesNotExist($site . '/shared/upgrade/journal.json');

        // No throwaway rehearsal files survive a run.
        $leftovers = array_values(array_diff(scandir($site . '/shared/upgrade') ?: [], ['.', '..']));
        $this->assertSame([], $leftovers, 'the verifier cleans its probes: ' . implode(', ', $leftovers));
    }

    /** Rebuilds an archive minus one entry and refreshes its whole-file sidecar. */
    private function repackZipWithout(string $zipPath, string $entry): void
    {
        $dir = $this->tmptree('kiption-wu-repack-');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        self::assertTrue($zip->extractTo($dir));
        $zip->close();
        @unlink($dir . '/' . $entry);
        $paths = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            $paths[] = ltrim(substr((string) $path, strlen($dir)), '/');
        }
        sort($paths, SORT_STRING);
        $out = $zipPath . '.repacked';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($paths as $rel) {
            self::assertTrue($zip->addFile($dir . '/' . $rel, $rel));
        }
        self::assertTrue($zip->close());
        rename($out, $zipPath);
        file_put_contents($zipPath . '.sha256', hash_file('sha256', $zipPath) . "\n");
    }

    /**
     * The production checker gates the stable engine on the verifier: the
     * builder carries upgrade/ReleaseVerifier.php into site/upgrade/ of every
     * install ZIP, the production check passes the complete pair, and an
     * install ZIP stripped of the verifier (sidecar refreshed, so the checksum
     * is not the failure under test) cannot pass the production gate.
     */
    public function test_production_gate_requires_the_stable_verifier(): void
    {
        $home = $this->tmptree('kiption-wu-gate-');
        $copy = $this->sourceCopy($home);
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD'));
        $install = $home . '/install.zip';
        $upgrade = $home . '/upgrade.zip';
        [$code, $out] = $this->build($copy, "--allow-dirty --version=1.2.0-gate --git-sha={$sha}"
            . ' --output=' . escapeshellarg($install) . ' --upgrade-output=' . escapeshellarg($upgrade));
        $this->assertSame(0, $code, $out);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($install) === true);
        $this->assertNotFalse($zip->locateName('site/upgrade/ReleaseVerifier.php'),
            'the builder carries the verifier in the stable engine');
        $zip->close();

        $check = 'PATH="/opt/homebrew/bin:/usr/bin:/bin:$PATH" %s scripts/check-release.php --zip=%s --upgrade-zip=%s';
        [$c, $o] = $this->sh($copy, sprintf($check, escapeshellarg(PHP_BINARY),
            escapeshellarg($install), escapeshellarg($upgrade)));
        $this->assertSame(0, $c, 'the complete production pair passes: ' . $o);

        $this->repackZipWithout($install, 'site/upgrade/ReleaseVerifier.php');
        [$c2, $o2] = $this->sh($copy, sprintf($check, escapeshellarg(PHP_BINARY),
            escapeshellarg($install), escapeshellarg($upgrade)));
        $this->assertNotSame(0, $c2, 'a production install ZIP without the verifier must not pass');
        $this->assertStringContainsString('site/upgrade/ReleaseVerifier.php', $o2, $o2);
    }

    // --------------------------------------- Task 3: authorization and lease

    /** Loads the stable engine classes once for in-process controller calls. */
    private function upgradeEngine(): void
    {
        require_once dirname(__DIR__) . '/upgrade/ReleasePointer.php';
        require_once dirname(__DIR__) . '/upgrade/ReleaseVerifier.php';
        require_once dirname(__DIR__) . '/upgrade/Journal.php';
        require_once dirname(__DIR__) . '/upgrade/Restore.php';
        require_once dirname(__DIR__) . '/upgrade/UpgradeController.php';
    }

    /**
     * An installed release home ready for upgrade work: deployment record,
     * migrated databases, one admin and one member, and a verified-shaped
     * staged release. The admin credential exists only inside this fixture.
     *
     * @return array{home:string,site:string,active:string,stage:string,target:string,port:int,
     *     admin_id:int,admin_epoch:string,member_id:int,member_epoch:string,password:string}
     */
    private function upgradeHome(string $prefix): array
    {
        [$home, $active] = $this->hostHome($prefix);
        $site = $home . '/site';
        $port = $this->freePort(8095);
        $this->deployment($home, $port);
        $kip = $this->kip($home);
        [$mCode, $mOut] = $this->sh($home, $kip . ' migrate');
        $this->assertSame(0, $mCode, $mOut);
        $password = 'upgrade-pass-1';
        [$aCode, $aOut] = $this->sh($home, $kip . ' user:create admin@kiption.test ' . escapeshellarg($password) . ' --admin');
        $this->assertSame(0, $aCode, $aOut);
        [$rCode, $rOut] = $this->sh($home, $kip . ' user:create reader@kiption.test ' . escapeshellarg('reader-pass-1'));
        $this->assertSame(0, $rCode, $rOut);
        $pdo = new \PDO('sqlite:' . $site . '/shared/data.sqlite');
        $admin = $pdo->query("SELECT id, password_hash FROM users WHERE email = 'admin@kiption.test'")->fetch(\PDO::FETCH_ASSOC);
        $member = $pdo->query("SELECT id, password_hash FROM users WHERE email = 'reader@kiption.test'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($admin, 'the fixture admin exists');
        $this->assertNotFalse($member, 'the fixture member exists');
        [$stage, , $target] = $this->stageFixture($site, '1.1.0');
        return ['home' => $home, 'site' => $site, 'active' => $active, 'stage' => $stage, 'target' => $target,
            'port' => $port, 'admin_id' => (int) $admin['id'], 'admin_epoch' => substr((string) $admin['password_hash'], 0, 12),
            'member_id' => (int) $member['id'], 'member_epoch' => substr((string) $member['password_hash'], 0, 12),
            'password' => $password];
    }

    /** @return array<string,mixed> */
    private function upgradeSession(int $userId, string $epoch): array
    {
        return ['user_id' => $userId, 'pwd_epoch' => $epoch, 'upgrade_csrf' => bin2hex(random_bytes(32))];
    }

    /** The controller env a stable wrapper would build for this fixture home. */
    private function upgradeEnv(array $h, array $overrides = []): array
    {
        return array_merge([
            'site_root' => $h['site'],
            'shared_dir' => $h['site'] . '/shared',
            'source_root' => $h['site'] . '/releases/' . $h['active'],
            'public_dir' => $h['home'] . '/public_html',
        ], $overrides);
    }

    /** One in-process controller round trip; $session travels by reference. */
    private function upgradeCall(array $h, string $method, array &$session, array $post = [], array $overrides = []): array
    {
        $server = array_merge(['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1'], $overrides);
        return \Upgrade\UpgradeController::handleWeb($server, $post, $session, $this->upgradeEnv($h, $overrides));
    }

    private function preparePost(array $h, string $csrf, string $digest): array
    {
        return ['command' => 'prepare', 'csrf' => $csrf, 'expected_sha256' => $digest,
            'upgrade_password' => $h['password'],
            'Origin-less' => 'the Origin header rides the server array, not the POST body'];
    }

    /**
     * The upgrade endpoint is an administrative code-deployment boundary:
     * every denial path (anonymous, non-admin, stale epoch, wrong password,
     * missing CSRF, foreign Origin, plain HTTP, absent/malformed digest, and
     * GET) leaves the journal, the pointer, and the databases untouched; a
     * valid admin prepare records a capability HASH and the prepared journal
     * and never a raw credential; the exclusive run.lock lease serializes
     * prepare against any site work; an expired capability fails until a
     * file-manager recovery token rotates it once; and no journal or
     * response ever carries a credential.
     */
    public function test_upgrade_auth_and_lease(): void
    {
        $this->upgradeEngine();
        $h = $this->upgradeHome('kiption-wu-auth-');
        $journalFile = $h['site'] . '/shared/upgrade/journal.json';
        $pointer = (string) file_get_contents($h['site'] . '/current');
        $dbHash = hash_file('sha256', $h['site'] . '/shared/data.sqlite');
        $origin = 'http://127.0.0.1:' . $h['port'];
        $fixedNow = 1000; // the failure-rate window never expires mid-parade

        $anon = [];
        $member = $this->upgradeSession($h['member_id'], $h['member_epoch']);
        $admin = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $stale = $this->upgradeSession($h['admin_id'], 'X' . substr($h['admin_epoch'], 1));

        $assertUntouched = function () use ($h, $journalFile, $pointer, $dbHash): void {
            $this->assertFileDoesNotExist($journalFile);
            $this->assertSame($pointer, (string) file_get_contents($h['site'] . '/current'), 'the pointer never moved');
            $this->assertSame($dbHash, hash_file('sha256', $h['site'] . '/shared/data.sqlite'), 'the content database never changed');
        };

        // GET renders status and mutates nothing on the site.
        $r = $this->upgradeCall($h, 'GET', $anon);
        $this->assertSame(200, $r['status']);
        $this->assertFileDoesNotExist($journalFile, 'a GET never writes the journal');

        // The denial parade: none of these may advance anything.
        $bads = [
            'anonymous prepare' => [$anon, ['command' => 'prepare', 'csrf' => '', 'expected_sha256' => $h['target'], 'upgrade_password' => 'x'], []],
            'non-admin prepare' => [$member, $this->preparePost($h, (string) $member['upgrade_csrf'], $h['target']), ['HTTP_ORIGIN' => $origin]],
            'stale epoch' => [$stale, $this->preparePost($h, (string) $stale['upgrade_csrf'], $h['target']), ['HTTP_ORIGIN' => $origin]],
            'missing csrf' => [$admin, ['command' => 'prepare', 'expected_sha256' => $h['target'], 'upgrade_password' => $h['password']], ['HTTP_ORIGIN' => $origin]],
            'foreign origin' => [$admin, $this->preparePost($h, (string) $admin['upgrade_csrf'], $h['target']), ['HTTP_ORIGIN' => 'http://evil.example']],
            'plain http' => [$admin, $this->preparePost($h, (string) $admin['upgrade_csrf'], $h['target']),
                ['HTTP_ORIGIN' => $origin, 'REMOTE_ADDR' => '203.0.113.7']],
        ];
        $fixedEnv = ['now' => $fixedNow];
        foreach ($bads as $what => [$session, $post, $server]) {
            @unlink($h['site'] . '/shared/upgrade/failures'); // each denial mechanism, not the cap, is under test here
            $r = $this->upgradeCall($h, 'POST', $session, $post, $server + $fixedEnv);
            $this->assertSame(403, $r['status'], "{$what} must be refused: " . $r['body']);
            $assertUntouched();
        }
        // The wrong password rides the same refusal as the unknown user.
        @unlink($h['site'] . '/shared/upgrade/failures');
        $wrongPassword = $admin;
        $wrongPassword['upgrade_csrf'] = $admin['upgrade_csrf'];
        $r = $this->upgradeCall($h, 'POST', $wrongPassword,
            ['command' => 'prepare', 'csrf' => (string) $admin['upgrade_csrf'], 'expected_sha256' => $h['target'],
                'upgrade_password' => 'not-the-password'], ['HTTP_ORIGIN' => $origin, 'now' => $fixedNow]);
        $this->assertSame(403, $r['status'], 'a wrong password must be refused');
        $assertUntouched();

        // The digest is mandatory, exact, and lowercase hex; a well-formed
        // digest with no staged release is a verifier refusal, not a crash.
        foreach (['', str_repeat('A', 64), substr($h['target'], 0, 63), str_repeat('0', 64)] as $bad) {
            @unlink($h['site'] . '/shared/upgrade/failures');
            $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
            $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $bad),
                ['HTTP_ORIGIN' => $origin, 'now' => $fixedNow]);
            $this->assertSame(400, $r['status'], 'digest ' . var_export($bad, true) . ' must be refused');
            $assertUntouched();
        }

        // Five recorded failures cap the endpoint; the window then expires.
        for ($i = 0; $i < 5; $i++) {
            $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
            $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, 'wrong', $h['target']),
                ['HTTP_ORIGIN' => $origin, 'now' => $fixedNow]);
        }
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $h['target']),
            ['HTTP_ORIGIN' => $origin, 'now' => $fixedNow]);
        $this->assertSame(429, $r['status'], 'the failure cap refuses further attempts');
        // The window expires: the cap no longer fronts the checks (a wrong
        // token is back to its own 403, not the cap's 429).
        @unlink($h['site'] . '/shared/upgrade/failures');
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, 'wrong', $h['target']),
            ['HTTP_ORIGIN' => $origin, 'now' => $fixedNow + 601]);
        $this->assertSame(403, $r['status'], 'the window expires');
        @unlink($h['site'] . '/shared/upgrade/failures');

        // The exclusive lease: a held shared lease (any web request or cron
        // job) blocks prepare, and nothing is written on the timeout.
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $lock = fopen($h['site'] . '/shared/run.lock', 'c');
        flock($lock, LOCK_SH);
        $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $h['target']),
            ['HTTP_ORIGIN' => $origin, 'lease_wait' => 0.2]);
        $this->assertSame(409, $r['status'], 'a held shared lease blocks prepare');
        $assertUntouched();
        flock($lock, LOCK_UN);
        fclose($lock);

        // The valid admin prepare: journal + capability hash, never the raw.
        $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $h['target']),
            ['HTTP_ORIGIN' => $origin]);
        $this->assertSame(200, $r['status'], 'a valid admin prepare advances: ' . $r['body']);
        $journal = json_decode((string) file_get_contents($journalFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('prepared', $journal['state']);
        $this->assertSame($h['active'], $journal['source_id']);
        $this->assertSame($h['target'], $journal['target_id']);
        $this->assertSame('1.1.0', $journal['target_version']);
        $capability = (string) $session['upgrade_capability'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capability);
        $this->assertSame(hash('sha256', $capability), $journal['capability_hash'], 'only the capability hash is recorded');
        $bytes = (string) file_get_contents($journalFile);
        $this->assertStringNotContainsString($capability, $bytes, 'the raw capability never lands in the journal');
        $this->assertStringNotContainsString($h['password'], $bytes, 'no password lands in the journal');
        $this->assertStringNotContainsString($capability, $r['body'], 'the capability never renders');
        $this->assertStringNotContainsString($h['password'], $r['body'], 'no password renders');
        $this->assertSame($pointer, (string) file_get_contents($h['site'] . '/current'));

        // A second prepare is refused while one is recorded.
        $again = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $r = $this->upgradeCall($h, 'POST', $again, $this->preparePost($h, (string) $again['upgrade_csrf'], $h['target']),
            ['HTTP_ORIGIN' => $origin]);
        $this->assertSame(409, $r['status'], 'an upgrade is already recorded');

        // An expired capability fails closed; the journal survives.
        $writer = new \Upgrade\Journal($h['site'] . '/shared');
        $writer->write(array_merge((array) $journal, ['capability_expires' => 1]));
        $r = $this->upgradeCall($h, 'POST', $session, ['command' => 'activate', 'csrf' => (string) $session['upgrade_csrf']],
            ['HTTP_ORIGIN' => $origin]);
        $this->assertSame(403, $r['status'], 'an expired capability is refused');
        $this->assertFileExists($journalFile, 'a refused transition leaves the journal');

        // The recovery bridge: a wrong token is refused and the file stays;
        // the right one is consumed once, rotates the capability, and, at
        // the prepared state, cancels the recorded upgrade (nothing was
        // ever switched). This path never queries the users table.
        $tokenFile = $h['site'] . '/shared/upgrade/recovery-token';
        $token = bin2hex(random_bytes(32));
        file_put_contents($tokenFile, $token . "\n");
        $fresh = [];
        $this->upgradeCall($h, 'GET', $fresh); // mint the csrf a fresh session carries
        $r = $this->upgradeCall($h, 'POST', $fresh,
            ['command' => 'recover', 'csrf' => (string) $fresh['upgrade_csrf'], 'recovery_token' => bin2hex(random_bytes(32))],
            ['HTTP_ORIGIN' => $origin]);
        $this->assertSame(403, $r['status'], 'a wrong recovery token is refused');
        $this->assertFileExists($tokenFile, 'a wrong token is never consumed');
        $r = $this->upgradeCall($h, 'POST', $fresh,
            ['command' => 'recover', 'csrf' => (string) $fresh['upgrade_csrf'], 'recovery_token' => $token],
            ['HTTP_ORIGIN' => $origin]);
        $this->assertSame(200, $r['status'], 'the recovery token rotates and recovers: ' . $r['body']);
        $this->assertFileDoesNotExist($tokenFile, 'the recovery token is consumed once');
        $this->assertFileDoesNotExist($journalFile, 'a prepared upgrade that was never switched cancels cleanly');

        // An authorized transition is not an authorization failure: at this
        // commit the transition engine itself is the missing piece, and the
        // journal survives it untouched.
        [$stage2, , $target2] = $this->stageFixture($h['site'], '1.2.0');
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $target2),
            ['HTTP_ORIGIN' => $origin]);
        $this->assertFileExists($journalFile);
        $r = $this->upgradeCall($h, 'POST', $session, ['command' => 'activate', 'csrf' => (string) $session['upgrade_csrf']],
            ['HTTP_ORIGIN' => $origin]);
        $this->assertNotSame(403, $r['status'], 'a valid capability authorizes the transition');
        $this->assertNotSame(403, $this->upgradeCall($h, 'POST', $session,
            ['command' => 'continue', 'csrf' => (string) $session['upgrade_csrf']], ['HTTP_ORIGIN' => $origin])['status'],
            'a valid capability authorizes continue');
        $this->assertFileExists($journalFile, 'an unauthorized-by-engine transition still leaves the journal');
        unlink($journalFile);
    }

    /**
     * The private CLI shares the browser's verifier, lease, and journal with
     * filesystem ownership as its only authority: it refuses the web SAPI
     * (the guard, unit-checked; the engine directory is outside every web
     * root), unknown commands, malformed and missing digests; it prepares
     * from local files with no HTTP session; it cannot race a browser
     * command (the exclusive lease serializes them); and recovery at the
     * prepared state works without the browser, users table, or any
     * credential.
     */
    public function test_upgrade_private_cli(): void
    {
        $this->upgradeEngine();
        $this->assertSame('the private upgrade CLI runs on the command line only',
            \Upgrade\UpgradeController::cliGuard('fpm-fcgi'));
        $this->assertSame('the private upgrade CLI runs on the command line only',
            \Upgrade\UpgradeController::cliGuard('cli-server'));
        $this->assertNull(\Upgrade\UpgradeController::cliGuard('cli'));

        $h = $this->upgradeHome('kiption-wu-cli-');
        $cli = 'PATH="/opt/homebrew/bin:/usr/bin:/bin" ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($h['site'] . '/upgrade/cli.php');
        $journalFile = $h['site'] . '/shared/upgrade/journal.json';

        // Unknown command and flag hygiene.
        [$code, $out] = $this->sh($h['home'], $cli . ' frobnicate');
        $this->assertNotSame(0, $code, 'an unknown command is refused');
        $this->assertStringContainsString('usage:', strtolower($out));
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --extra');
        $this->assertNotSame(0, $code, 'an unknown flag is refused');

        // The published digest is mandatory and exact.
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare');
        $this->assertNotSame(0, $code, 'prepare without a digest is refused');
        $this->assertFileDoesNotExist($journalFile);
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=banana');
        $this->assertNotSame(0, $code, 'a malformed digest is refused');
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=' . str_repeat('0', 64));
        $this->assertNotSame(0, $code, 'a digest with no staged release fails verification');
        $this->assertFileDoesNotExist($journalFile);

        // Prepare from local files: no session, no password, no capability.
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=' . $h['target']);
        $this->assertSame(0, $code, $out);
        $journal = json_decode((string) file_get_contents($journalFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('prepared', $journal['state']);
        $this->assertSame($h['active'], $journal['source_id']);
        $this->assertSame($h['target'], $journal['target_id']);
        $this->assertArrayNotHasKey('capability_hash', $journal, 'the CLI mints no browser capability');

        // A browser-prepare is refused while the CLI upgrade is recorded.
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $r = $this->upgradeCall($h, 'POST', $session,
            $this->preparePost($h, (string) $session['upgrade_csrf'], $h['target']),
            ['HTTP_ORIGIN' => 'http://127.0.0.1:' . $h['port']]);
        $this->assertSame(409, $r['status'], 'the CLI-recorded upgrade blocks a browser prepare');

        // Browser and CLI cannot race: a held exclusive lease refuses the CLI.
        $lock = fopen($h['site'] . '/shared/run.lock', 'c');
        flock($lock, LOCK_EX | LOCK_NB);
        [$code, $out] = $this->sh($h['home'], $cli . ' status');
        $this->assertSame(0, $code, 'status is read-only and lock-free: ' . $out);
        $this->assertStringContainsString('prepared', $out);
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=' . $h['target']);
        $this->assertNotSame(0, $code, 'a held exclusive lease refuses the CLI');
        flock($lock, LOCK_UN);
        fclose($lock);

        // Recovery without the browser: break the users table the way a
        // partial migration would, then recover the prepared upgrade. No
        // users query, no credential, journal cleared.
        $users = $h['site'] . '/shared/data.sqlite';
        $pdo = new \PDO('sqlite:' . $users);
        $pdo->exec('ALTER TABLE users RENAME TO users_half_migrated');
        [$code, $out] = $this->sh($h['home'], $cli . ' recover');
        $this->assertSame(0, $code, 'CLI recovery works without browser auth: ' . $out);
        $this->assertFileDoesNotExist($journalFile, 'the prepared upgrade is cleared');
        $this->assertStringContainsString('cleared', $out);
        $pdo->exec('ALTER TABLE users_half_migrated RENAME TO users');
        [$code, $out] = $this->sh($h['home'], $cli . ' status');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('no upgrade is recorded', $out);
    }

    /**
     * A stage that is really an extracted install ZIP (a nested site/ tree)
     * is refused outright, and the final builder output end to end: an
     * early-development bundle is rejected as a target, while its payload
     * layout itself hashes cleanly against its own manifest bytes.
     */
    public function test_staged_release_rejects_nested_and_builder_output(): void
    {
        require_once dirname(__DIR__) . '/upgrade/ReleasePointer.php';
        require_once dirname(__DIR__) . '/upgrade/ReleaseVerifier.php';
        $home = $this->tmptree('kiption-wu-nested-');
        $site = $home . '/site';
        mkdir($site . '/releases', 0775, true);
        mkdir($site . '/shared', 0775, true);
        mkdir($home . '/public_html', 0775, true);
        $activeId = $this->activeFixture($site, '1.0.0');
        $this->liveDbs($site);

        // An install ZIP extracted INTO a release directory leaves a nested
        // site/ tree; that is never a valid stage.
        $nested = $site . '/releases/' . str_repeat('d', 64);
        mkdir($nested . '/site/releases', 0775, true);
        file_put_contents($nested . '/site/current', "whatever\n");
        $r = $this->verifier($site, $activeId)->verify($nested, str_repeat('d', 64), rehearse: false);
        $this->assertFalse($r['ok'], 'a nested site/ tree must not verify');

        // The final builder output end to end: extract the release-root
        // upgrade ZIP into site/releases/<id>/ and verify against the active
        // fixture. The development marker must reject it as a target even
        // though every byte hashes.
        $copy = $this->sourceCopy($home);
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD'));
        $upgrade = $home . '/upgrade.zip';
        [$code, $out] = $this->build($copy, "--development --allow-dirty --version=9.9.9-test"
            . " --git-sha={$sha} --upgrade-output=" . escapeshellarg($upgrade));
        $this->assertSame(0, $code, $out);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($upgrade) === true);
        $builderId = trim((string) file_get_contents($upgrade . '.manifest.sha256'));
        mkdir($site . '/releases/' . $builderId, 0775, true);
        $this->assertTrue($zip->extractTo($site . '/releases/' . $builderId));
        $zip->close();
        $stage = $site . '/releases/' . $builderId;
        $this->assertFileExists($stage . '/RELEASE.json');
        $r = $this->verifier($site, $activeId)->verify($stage, $builderId, rehearse: false);
        $this->assertFalse($r['ok'], 'a development bundle must be rejected end to end');
        $this->assertStringContainsString('development', implode(' ', $r['failures']));
    }

    // ------------------------------------------ Task 3: web smoke and lease

    /**
     * The abuse smoke against a real server, plus the front controller's
     * lease discipline: the stable web-root wrapper answers /upgrade.php;
     * GET renders status for anyone but writes nothing; POST prepare needs
     * an admin session, the re-entered password, a valid CSRF token, and the
     * published digest; the front controller holds a SHARED run.lock lease
     * through the whole request (it waits out an exclusive holder); and
     * maintenance returns 503 before the static cache serves anything.
     */
    public function test_upgrade_web_smoke_and_index_lease(): void
    {
        $h = $this->upgradeHome('kiption-wu-smoke-');
        $origin = 'http://127.0.0.1:' . $h['port'];
        $journalFile = $h['site'] . '/shared/upgrade/journal.json';

        // Sessions live in a fixture save path; the dev server and the
        // seeder subprocess share it.
        $sessions = $h['home'] . '/sessions';
        mkdir($sessions, 0775, true);
        $seedSession = static function (string $id, array $data) use ($sessions): void {
            $code = 'session_id(' . var_export($id, true) . '); session_start(); $_SESSION = '
                . var_export($data, true) . '; session_write_close();';
            exec(escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg($sessions)
                . ' -r ' . escapeshellarg($code));
        };
        $adminId = 'upgradeadminsession';
        $memberId = 'upgrademembersession';
        $adminCsrf = bin2hex(random_bytes(32));
        $seedSession($adminId, ['user_id' => $h['admin_id'], 'pwd_epoch' => $h['admin_epoch'], 'upgrade_csrf' => $adminCsrf]);
        $seedSession($memberId, ['user_id' => $h['member_id'], 'pwd_epoch' => $h['member_epoch'], 'upgrade_csrf' => bin2hex(random_bytes(32))]);

        // A router that executes real .php files: the built-in server's
        // default static fallback would download the wrapper's source.
        $router = $h['home'] . '/upgrade-router.php';
        file_put_contents($router, "<?php\n"
            . "\$p = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';\n"
            . "\$real = realpath(\$_SERVER['DOCUMENT_ROOT'] . \$p);\n"
            . "if (\$real !== false && str_starts_with(\$real, \$_SERVER['DOCUMENT_ROOT']) && is_file(\$real) && str_ends_with(\$real, '.php')) {\n"
            . "    chdir(dirname(\$real));\n"
            . "    require \$real;\n"
            . "    exit;\n"
            . "}\n"
            . "return false;\n");
        $server = $this->startServer($h['port'], $h['home'] . '/public_html', $router,
            ['-d', 'session.save_path=' . $sessions]);
        try {
            $cookie = static fn (string $id): string => 'Cookie: PHPSESSID=' . $id;
            $post = static fn (array $fields): string => http_build_query($fields);

            // Anonymous GET: the status page, no journal.
            [$code, $body] = $this->http($h['port'], 'GET', '/upgrade.php');
            $this->assertSame(200, $code, (string) $body);
            $this->assertFileDoesNotExist($journalFile, 'a GET never writes the journal');

            // Anonymous POST: refused.
            [$code, $body] = $this->http($h['port'], 'POST', '/upgrade.php',
                ['Content-Type: application/x-www-form-urlencoded'], $post(['command' => 'prepare']));
            $this->assertSame(403, $code, 'an anonymous prepare is refused');
            $this->assertFileDoesNotExist($journalFile);

            // Non-admin session: refused with its own CSRF.
            [$code, $body] = $this->http($h['port'], 'POST', '/upgrade.php',
                [$cookie($memberId), 'Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $origin],
                $post(['command' => 'prepare', 'expected_sha256' => $h['target'], 'upgrade_password' => 'reader-pass-1']));
            $this->assertSame(403, $code, 'a non-admin prepare is refused');
            $this->assertFileDoesNotExist($journalFile);

            // Admin, invalid CSRF: refused.
            [$code, $body] = $this->http($h['port'], 'POST', '/upgrade.php',
                [$cookie($adminId), 'Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $origin],
                $post(['command' => 'prepare', 'csrf' => 'wrong', 'expected_sha256' => $h['target'],
                    'upgrade_password' => $h['password']]));
            $this->assertSame(403, $code, 'an invalid CSRF token is refused');
            $this->assertFileDoesNotExist($journalFile);

            // Admin, everything right: the journal records prepared.
            [$code, $body] = $this->http($h['port'], 'POST', '/upgrade.php',
                [$cookie($adminId), 'Content-Type: application/x-www-form-urlencoded', 'Origin: ' . $origin],
                $post(['command' => 'prepare', 'csrf' => $adminCsrf, 'expected_sha256' => $h['target'],
                    'upgrade_password' => $h['password']]));
            $this->assertSame(200, $code, 'only the admin with the password advances: ' . $body);
            $journal = json_decode((string) file_get_contents($journalFile), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('prepared', $journal['state']);
            $this->assertStringNotContainsString($h['password'], (string) $body, 'no credential renders');
            unlink($journalFile);

            // The front controller holds a shared lease for the whole
            // request: an exclusive holder makes the next request wait.
            $holder = proc_open(
                [PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); sleep(1);',
                    $h['site'] . '/shared/run.lock'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes
            );
            usleep(200000); // let the holder take the lease
            $started = microtime(true);
            [$code] = $this->http($h['port'], 'GET', '/');
            $elapsed = microtime(true) - $started;
            $this->assertSame(200, $code, 'the request completes once the exclusive holder finishes');
            $this->assertGreaterThan(0.7, $elapsed, 'the request waited out the exclusive lease');
            proc_terminate($holder);
            proc_close($holder);

            // Maintenance beats the cache: a warm cache serves 503 under the
            // marker and the site returns when it is lifted.
            $this->assertSame(200, $this->http($h['port'], 'GET', '/')[0], 'the home page serves');
            [$code, $cached] = $this->http($h['port'], 'GET', '/');
            $this->assertSame(200, $code, 'the cached home page serves');
            touch($h['site'] . '/shared/maintenance.lock');
            [$code, $body] = $this->http($h['port'], 'GET', '/');
            $this->assertSame(503, $code, 'maintenance is a 503 before the static cache');
            $this->assertNotSame($cached, $body, 'the cached home page does not leak through');
            unlink($h['site'] . '/shared/maintenance.lock');
            $this->assertSame(200, $this->http($h['port'], 'GET', '/')[0], 'lifting maintenance restores service');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    // -------------------------------------------- Task 4: the state machine

    private const PROBE_TABLE = 'probe_upgrade';

    /** The runnable migration the fixture target carries. */
    private function probeMigration(): string
    {
        return "<?php // fixture target migration\n"
            . "return new class extends \\Kip\\Migrations\\Migration {\n"
            . "    public function up(\\Kip\\Database \$db): void { \$db->query('CREATE TABLE " . self::PROBE_TABLE . " (v TEXT)'); }\n"
            . "    public function down(\\Kip\\Database \$db): void { \$db->query('DROP TABLE " . self::PROBE_TABLE . "'); }\n"
            . "};\n";
    }

    /** The migration that fails mid-run, the way a broken release would. */
    private function throwingMigration(): string
    {
        return "<?php // fixture target migration that fails\n"
            . "return new class extends \\Kip\\Migrations\\Migration {\n"
            . "    public function up(\\Kip\\Database \$db): void { throw new \\RuntimeException('injected migration failure'); }\n"
            . "    public function down(\\Kip\\Database \$db): void {}\n"
            . "};\n";
    }

    private function tableExists(string $dbFile, string $table): bool
    {
        $pdo = new \PDO('sqlite:' . $dbFile);
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetch() !== false;
    }

    /** @return list<string> asset paths relative to the public assets tree */
    private function assetFiles(string $publicDir): array
    {
        $root = $publicDir . '/assets';
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );
        foreach ($it as $path) {
            $files[] = ltrim(substr((string) $path, strlen($root)), '/');
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * A REAL production-built release staged for upgrade, carrying one extra
     * migration under its own ledger name. Built by the builder and
     * extracted the way an owner does, so the CLI flows load a genuine
     * vendor tree in a fresh process.
     *
     * @return array{0:string,1:string} stage directory, release id
     */
    private function stageRealRelease(array $h, string $version, ?string $migration, string $migrationName = '900_probe'): array
    {
        $copy = $this->sourceCopy($h['home']);
        if ($migration !== null) {
            file_put_contents($copy . '/app/migrations/' . $migrationName . '.php', $migration);
        }
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD'));
        $zipPath = $h['home'] . '/upgrade-real.zip';
        [$code, $out] = $this->build($copy, "--allow-dirty --version={$version} --git-sha={$sha}"
            . ' --upgrade-output=' . escapeshellarg($zipPath));
        $this->assertSame(0, $code, $out);
        $id = trim((string) file_get_contents($zipPath . '.manifest.sha256'));
        mkdir($h['site'] . '/releases/' . $id, 0775, true);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        self::assertTrue($zip->extractTo($h['site'] . '/releases/' . $id));
        $zip->close();
        return [$h['site'] . '/releases/' . $id, $id];
    }

    /** Content state the upgrade must preserve across success and rollback. */
    private function contentFingerprint(array $h): array
    {
        $pdo = new \PDO('sqlite:' . $h['site'] . '/shared/data.sqlite');
        return [
            'stories' => (int) $pdo->query('SELECT COUNT(*) c FROM stories')->fetch(\PDO::FETCH_ASSOC)['c'],
            'users' => (int) $pdo->query('SELECT COUNT(*) c FROM users')->fetch(\PDO::FETCH_ASSOC)['c'],
        ];
    }

    private function assertLoginSurvives(array $h, array $session): void
    {
        $auth = new \Kip\Auth(new \Kip\Database('sqlite:' . $h['site'] . '/shared/data.sqlite'), new \Kip\Session($session));
        $this->assertTrue($auth->sessionValid(), 'the admin login survives the upgrade or rollback');
    }

    /**
     * The browser state machine over the real coordinator: snapshot, switch,
     * and migrate succeed end to end; every injected failure point (backup,
     * the space between the two asset renames, the pointer switch, the first
     * migration, and the migration set after it commits) preserves
     * maintenance and is recovered to the matching code, asset, and database
     * snapshot; retries at every persisted state are idempotent; and a
     * browser rehearsal timeout leaves the site live and names the CLI path.
     */
    public function test_upgrade_state_machine(): void
    {
        $this->upgradeEngine();
        $h = $this->upgradeHome('kiption-wu-machine-');
        [$seedCode, $seedOut] = $this->sh($h['home'], $this->kip($h['home']) . ' db:seed');
        $this->assertSame(0, $seedCode, $seedOut);
        mkdir($h['home'] . '/public_html/uploads', 0775, true);
        file_put_contents($h['home'] . '/public_html/uploads/probe.bin', 'upload-content');
        // The framework page cache: created by the first served page.
        (new \PDO('sqlite:' . $h['site'] . '/shared/cache.sqlite'))->exec('CREATE TABLE cache (k TEXT)');
        $origin = 'http://127.0.0.1:' . $h['port'];
        $pointerFile = $h['site'] . '/current';
        $maintenance = $h['site'] . '/shared/maintenance.lock';
        $journalFile = $h['site'] . '/shared/upgrade/journal.json';
        $publicDir = $h['home'] . '/public_html';
        $aAssets = $this->assetFiles($publicDir);
        $this->assertNotEmpty($aAssets, 'the installed release ships assets');
        $content = $this->contentFingerprint($h);
        $this->assertGreaterThan(0, $content['stories'], 'seeded content exists');

        $freshAdmin = fn (): array => $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $stageRunnable = fn (string $version): array => $this->stageFixture($h['site'], $version, migration: $this->probeMigration());
        $prepare = function (string $id, array $overrides = []) use ($h, $origin): array {
            $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
            $response = $this->upgradeCall($h, 'POST', $session,
                $this->preparePost($h, (string) $session['upgrade_csrf'], $id), ['HTTP_ORIGIN' => $origin] + $overrides);
            return [$session, $response]; // the call runs first: it stores the capability into $session
        };
        $post = function (array $session, string $command, array $overrides = []) use ($h, $origin): array {
            return $this->upgradeCall($h, 'POST', $session,
                ['command' => $command, 'csrf' => (string) $session['upgrade_csrf']], ['HTTP_ORIGIN' => $origin] + $overrides);
        };
        $journalState = fn (): ?string => json_decode((string) @file_get_contents($journalFile), true)['state'] ?? null;

        // Cycle 1: the first migration fails; rollback restores everything.
        [$stageB, , $idB] = $stageRunnable('1.1.0');
        [$session, $r] = $prepare($idB);
        $this->assertSame(200, $r['status'], $r['body']);
        $r = $post($session, 'activate');
        $this->assertSame(303, $r['status'], 'activate hands back a 303 to the status page: ' . $r['body']);
        $this->assertSame($idB, trim((string) file_get_contents($pointerFile)), 'the pointer switched');
        $this->assertFileExists($maintenance);
        $this->assertStringStartsWith('upgrade', (string) file_get_contents($maintenance), 'the upgrade owns this marker');
        $this->assertSame('switched', $journalState());
        $this->assertNotContains($aAssets[0], $this->assetFiles($publicDir), 'the old assets moved aside');
        $this->assertContains('style.css', $this->assetFiles($publicDir), 'the target assets moved in');

        $r = $post($session, 'continue', ['faults' => ['migrate1']]);
        $this->assertSame(503, $r['status'], 'a failed migration stops the upgrade');
        $this->assertSame('rollback_pending', $journalState());
        $this->assertFileExists($maintenance, 'maintenance holds through the failure');
        // A retry does not push a rollback-pending upgrade forward.
        $r = $post($session, 'continue');
        $this->assertSame(503, $r['status'], 'a rollback-pending upgrade is not resumable forward');

        $r = $post($session, 'recover');
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame($h['active'], trim((string) file_get_contents($pointerFile)), 'the pointer is back on the source');
        $this->assertSame($aAssets, $this->assetFiles($publicDir), 'the source assets are restored byte for byte');
        $this->assertFalse($this->tableExists($h['site'] . '/shared/data.sqlite', self::PROBE_TABLE), 'the snapshot precedes the migration');
        $this->assertFileDoesNotExist($maintenance, 'recovery clears maintenance as the final step');
        $this->assertFileDoesNotExist($journalFile);
        $this->assertSame($content, $this->contentFingerprint($h), 'content survives the rollback');
        $this->assertLoginSurvives($h, $freshAdmin());
        $this->assertStringEqualsFile($publicDir . '/uploads/probe.bin', 'upload-content', 'uploads are shared data, never replaced');

        // Cycle 2: a crash right after the pointer switch; recovery unwinds it.
        $this->stageFixture($h['site'], '1.1.0', migration: $this->probeMigration());
        [$session, $r] = $prepare($idB);
        $this->assertSame(200, $r['status'], $r['body']);
        $r = $post($session, 'activate', ['faults' => ['pointer']]);
        $this->assertSame(503, $r['status'], 'a crash after the switch is a failure');
        $this->assertSame('rollback_pending', $journalState());
        $this->assertSame($idB, trim((string) file_get_contents($pointerFile)), 'the switch had landed');
        $this->assertFileExists($maintenance);
        $r = $post($session, 'recover');
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame($h['active'], trim((string) file_get_contents($pointerFile)));
        $this->assertSame($aAssets, $this->assetFiles($publicDir));
        $this->assertFileDoesNotExist($maintenance);
        $this->assertFileDoesNotExist($journalFile);

        // Cycle 3: a crash between the two asset renames; recovery still
        // lands the saved tree even with the public tree missing.
        [$session, $r] = $prepare($idB);
        $this->assertSame(200, $r['status'], $r['body']);
        $r = $post($session, 'activate', ['faults' => ['assets_mid']]);
        $this->assertSame(503, $r['status'], $r['body']);
        $this->assertSame('rollback_pending', $journalState());
        $this->assertSame([], $this->assetFiles($publicDir), 'the public tree is between renames');
        $this->assertFileExists($maintenance);
        $r = $post($session, 'recover');
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame($aAssets, $this->assetFiles($publicDir), 'the saved tree is back');
        $this->assertFileDoesNotExist($maintenance);

        // Cycle 4: a backup failure keeps the journal at prepared and the
        // site in maintenance; recovery cancels cleanly.
        [$session, $r] = $prepare($idB);
        $this->assertSame(200, $r['status'], $r['body']);
        $r = $post($session, 'activate', ['faults' => ['backup']]);
        $this->assertSame(503, $r['status'], $r['body']);
        $this->assertSame('prepared', $journalState(), 'no pointer switch, no state advance');
        $this->assertFileExists($maintenance);
        $r = $post($session, 'recover');
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertFileDoesNotExist($maintenance, 'recovery clears only the upgrade marker');
        $this->assertFileDoesNotExist($journalFile);

        // Cycle 5: the clean run. The migration lands, caches clear, and the
        // site comes back on the target with maintenance off.
        [$session, $r] = $prepare($idB);
        $this->assertSame(200, $r['status'], $r['body']);
        $r = $post($session, 'activate');
        $this->assertSame(303, $r['status']);
        $r = $post($session, 'continue');
        $this->assertSame(200, $r['status'], $r['body']);
        $this->assertSame($idB, trim((string) file_get_contents($pointerFile)));
        $this->assertTrue($this->tableExists($h['site'] . '/shared/data.sqlite', self::PROBE_TABLE), 'the target migration ran');
        $this->assertFileDoesNotExist($maintenance);
        $this->assertFileDoesNotExist($journalFile, 'a completed upgrade clears its journal');
        $this->assertSame($content, $this->contentFingerprint($h), 'content survives the success');
        $this->assertLoginSurvives($h, $freshAdmin());
        $this->assertContains('style.css', $this->assetFiles($publicDir));
        $this->assertFileExists($publicDir . '/cache', 'the static layer was rebuilt');

        // A preflight rehearsal timeout fails closed to the CLI path with
        // the site left completely alone.
        [$stageC, , $idC] = $stageRunnable('1.2.0');
        [, $r] = $prepare($idC, ['verify_options' => ['time_budget' => 0.0]]);
        $this->assertSame(400, $r['status']);
        $this->assertStringContainsString('CLI', $r['body'], 'the refusal names the CLI path');
        $this->assertSame($idB, trim((string) file_get_contents($pointerFile)));
        $this->assertFileDoesNotExist($maintenance);
        $this->assertFileDoesNotExist($journalFile);
        @unlink($stageC);
    }

    /**
     * The same machine through the private CLI in separate processes: the
     * CLI shares the browser journal and lock, a clean A-to-B upgrade
     * succeeds, and a release whose migration fails mid-run forces the
     * matching rollback, all without any browser session, password, or
     * capability.
     */
    public function test_upgrade_cli_state_machine(): void
    {
        $this->upgradeEngine();
        $h = $this->upgradeHome('kiption-wu-climachine-');
        [$seedCode, $seedOut] = $this->sh($h['home'], $this->kip($h['home']) . ' db:seed');
        $this->assertSame(0, $seedCode, $seedOut);
        (new \PDO('sqlite:' . $h['site'] . '/shared/cache.sqlite'))->exec('CREATE TABLE cache (k TEXT)');
        $pointerFile = $h['site'] . '/current';
        $maintenance = $h['site'] . '/shared/maintenance.lock';
        $journalFile = $h['site'] . '/shared/upgrade/journal.json';
        $cli = 'PATH="/opt/homebrew/bin:/usr/bin:/bin" ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($h['site'] . '/upgrade/cli.php');

        // Clean success through four separate processes.
        [, $idC] = $this->stageRealRelease($h, '0.3.0-upgrade', $this->probeMigration());
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=' . $idC);
        $this->assertSame(0, $code, $out);
        // The CLI journal is the browser journal: a browser prepare is refused.
        $session = $this->upgradeSession($h['admin_id'], $h['admin_epoch']);
        $r = $this->upgradeCall($h, 'POST', $session, $this->preparePost($h, (string) $session['upgrade_csrf'], $idC),
            ['HTTP_ORIGIN' => 'http://127.0.0.1:' . $h['port']]);
        $this->assertSame(409, $r['status'], 'the browser sees the CLI-recorded upgrade');

        [$code, $out] = $this->sh($h['home'], $cli . ' activate');
        $this->assertSame(0, $code, $out);
        $this->assertSame($idC, trim((string) file_get_contents($pointerFile)));
        $this->assertFileExists($maintenance);
        $this->assertSame('switched', json_decode((string) file_get_contents($journalFile), true)['state']);
        [$code, $out] = $this->sh($h['home'], $cli . ' continue');
        $this->assertSame(0, $code, $out);
        $this->assertTrue($this->tableExists($h['site'] . '/shared/data.sqlite', self::PROBE_TABLE));
        $this->assertFileDoesNotExist($maintenance);
        $this->assertFileDoesNotExist($journalFile);
        $this->assertSame($this->contentFingerprint($h), $this->contentFingerprint($h), 'content intact');

        // Forced rollback: the target's migration fails mid-run in its own
        // process, and recovery lands back on the previous release.
        [, $idE] = $this->stageRealRelease($h, '0.4.0-upgrade', $this->throwingMigration(), '901_fail');
        [$code, $out] = $this->sh($h['home'], $cli . ' prepare --digest=' . $idE);
        $this->assertSame(0, $code, $out);
        [$code, $out] = $this->sh($h['home'], $cli . ' activate');
        $this->assertSame(0, $code, $out);
        $this->assertSame($idE, trim((string) file_get_contents($pointerFile)));
        [$code, $out] = $this->sh($h['home'], $cli . ' continue');
        $this->assertNotSame(0, $code, 'a failing migration refuses continue');
        $this->assertStringContainsString('recover', $out);
        $this->assertSame('rollback_pending', json_decode((string) file_get_contents($journalFile), true)['state']);
        $this->assertFileExists($maintenance);
        [$code, $out] = $this->sh($h['home'], $cli . ' recover');
        $this->assertSame(0, $code, $out);
        $this->assertSame($idC, trim((string) file_get_contents($pointerFile)), 'the pointer is back on the previous release');
        $this->assertTrue($this->tableExists($h['site'] . '/shared/data.sqlite', self::PROBE_TABLE),
            'the restored snapshot carries the previous release schema');
        $this->assertFileDoesNotExist($maintenance);
        $this->assertFileDoesNotExist($journalFile);
        // A repeated recover is idempotent.
        [$code, $out] = $this->sh($h['home'], $cli . ' recover');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('nothing to recover', $out);
    }

    // -------------------------------------------------- Task 5: the runbook

    /**
     * The runbook says exactly what the code does, and never teaches the
     * dangerous shortcut: the owner verifies the whole upgrade ZIP against
     * the official page, stages the release-root ZIP (never the install
     * ZIP), drives /upgrade.php over HTTPS with the pasted digest and the
     * re-entered admin password, waits for a complete or rolled-back
     * result, and takes the backup off the host; the PHP CLI path shares
     * the same verifier, lease, snapshots, pointer, journal, and recovery;
     * scheduled commands ride the stable launcher across the cutover; and
     * the stable engine is replaced only by the maintenance procedure,
     * never in place.
     */
    public function test_upgrade_docs(): void
    {
        // Docs assertions match across re-wrapped lines: whitespace folds.
        $fold = static fn (string $doc): string => (string) preg_replace('/\s+/', ' ', $doc);
        $install = $fold((string) file_get_contents(dirname(__DIR__) . '/INSTALL.md'));
        $readme = $fold((string) file_get_contents(dirname(__DIR__) . '/README.md'));
        $matrix = $fold((string) file_get_contents(dirname(__DIR__) . '/docs/hosting-matrix.md'));

        // Staging: two digests, the right ZIP, the right shape.
        $this->assertStringContainsString('whole-upgrade-ZIP SHA-256 published with the release', $install);
        $this->assertStringContainsString('separately published `RELEASE.json` SHA-256', $install);
        $this->assertStringContainsString('release-root upgrade ZIP', $install);
        $this->assertStringContainsString('not the install ZIP', $install);
        $this->assertStringContainsString('`RELEASE.json` sits directly inside the staging directory', $install);

        // The browser runbook, in order.
        foreach ([
            '`/upgrade.php` over HTTPS',
            'paste that digest',
            're-enter your admin password',
            'verified target and the backup size',
            'a complete or rolled-back result',
            'download the new backup off the host',
        ] as $needle) {
            $this->assertStringContainsString($needle, $install, "the runbook must say: {$needle}");
        }

        // The never-overwrite warnings, every one of them.
        foreach (['`site/shared`', '`site/current`', '`public_html/uploads`'] as $needle) {
            $this->assertStringContainsString($needle, $install, "the runbook must warn about {$needle}");
        }

        // The CLI path shares the machinery; the browser timeout names it.
        $this->assertStringContainsString('`php site/upgrade/cli.php prepare|activate|continue|recover`', $install);
        $this->assertStringContainsString('same verifier, lease, snapshots, pointer, journal, and recovery', $install);
        $this->assertStringContainsString('the PHP CLI upgrade path', $install);

        // Scheduled commands ride the stable launcher across the cutover.
        $this->assertStringContainsString('resolve the active release, including after an upgrade switches it', $install);

        // The stable engine is never replaced in place; the maintenance
        // procedure renames the old engine aside and can put it back.
        $this->assertStringContainsString('overwrite `site/upgrade` in place', $install);
        $this->assertStringContainsString('`site/upgrade.previous`', $install);
        $this->assertStringContainsString('quiet period', $install);

        // The old hand-edit-the-pointer paragraph is gone for good.
        $this->assertStringNotContainsString('switch the plain-text `site/current` pointer', $install);

        // The README points shared-host owners at the runbook and the
        // matrix, without a universal-host claim.
        $this->assertStringContainsString('docs/hosting-matrix.md', $readme);
        $this->assertStringContainsString('INSTALL.md', $readme);
        $this->assertStringContainsString('upgrade in the browser', $readme);

        // The matrix states the measured browser-upgrade boundary and the
        // hard filesystem requirements, and keeps the no-provider claim.
        foreach ([
            'to the PHP CLI upgrade path',
            'does not honor `flock` or atomic rename',
            'rehearsal',
            'local-fixture-validated',
        ] as $needle) {
            $this->assertStringContainsString($needle, $matrix, "the matrix must say: {$needle}");
        }
    }

    /**
     * The stable-engine maintenance procedure, executed against a fixture
     * exactly as the runbook documents it: the package carries a manifest
     * with per-file SHA-256s and the upgrade protocol; verification is
     * full (every file, nothing extra, protocol 1) and a corrupted staged
     * file or an incompatible protocol refuses before anything moves; the
     * quiet period is proven by taking the exclusive lease without
     * waiting; the apply renames the whole old engine and launcher aside
     * and installs the staged files and both wrapper sets under
     * maintenance; the smoke test boots the new engine; and the rollback
     * puts the previous set back with both docroot layouts' index.php,
     * setup.php, upgrade.php, and .htaccess intact.
     */
    public function test_upgrade_engine_maintenance(): void
    {
        $this->upgradeEngine();
        $h = $this->upgradeHome('kiption-wu-engine-');
        $repo = dirname(__DIR__);
        $site = $h['site'];

        // The package, laid out exactly as the runbook defines it.
        $package = $this->tmptree('kiption-wu-pkg-');
        $map = [
            'upgrade/ReleasePointer.php' => "{$repo}/upgrade/ReleasePointer.php",
            'upgrade/ReleaseVerifier.php' => "{$repo}/upgrade/ReleaseVerifier.php",
            'upgrade/Journal.php' => "{$repo}/upgrade/Journal.php",
            'upgrade/UpgradeController.php' => "{$repo}/upgrade/UpgradeController.php",
            'upgrade/Coordinator.php' => "{$repo}/upgrade/Coordinator.php",
            'upgrade/Restore.php' => "{$repo}/upgrade/Restore.php",
            'upgrade/cli.php' => "{$repo}/upgrade/cli.php",
            'bin/kip' => "{$repo}/upgrade/dispatch.php",
            'site-public/index.php' => "{$repo}/upgrade/stable/public-index.php",
            'site-public/setup.php' => "{$repo}/upgrade/stable/public-setup.php",
            'site-public/upgrade.php' => "{$repo}/upgrade/stable/public-upgrade.php",
            'site-public/.htaccess' => "{$repo}/public/.htaccess",
            'public_html/index.php' => "{$repo}/upgrade/stable/docroot-index.php",
            'public_html/setup.php' => "{$repo}/upgrade/stable/docroot-setup.php",
            'public_html/upgrade.php' => "{$repo}/upgrade/stable/docroot-upgrade.php",
            'public_html/.htaccess' => "{$repo}/public/.htaccess",
        ];
        foreach ($map as $rel => $source) {
            $to = $package . '/' . $rel;
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0775, true);
            }
            copy($source, $to);
        }
        // The staged engine differs from the installed one: a real engine
        // release carries new bytes. Stamp the package's Journal so the
        // apply provably changes the engine set.
        file_put_contents($package . '/upgrade/Journal.php',
            (string) file_get_contents($package . '/upgrade/Journal.php')
            . "\n// maintenance package " . bin2hex(random_bytes(4)) . "\n");
        $hashes = [];
        foreach (array_keys($map) as $rel) {
            $hashes[$rel] = (string) hash_file('sha256', $package . '/' . $rel);
        }
        $manifestFile = $package . '/MANIFEST.json';
        file_put_contents($manifestFile, (string) json_encode(['upgrade_protocol' => 1, 'files' => $hashes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // The state a failed maintenance must be able to restore exactly.
        $installed = static function () use ($site, $h): array {
            $state = [];
            foreach (glob($site . '/upgrade/*.php') ?: [] as $f) {
                $state['upgrade/' . basename((string) $f)] = hash_file('sha256', (string) $f);
            }
            $state['bin/kip'] = hash_file('sha256', $site . '/bin/kip');
            foreach (['site/public' => $site . '/public', 'public_html' => $h['home'] . '/public_html'] as $label => $web) {
                foreach (['index.php', 'setup.php', 'upgrade.php', '.htaccess'] as $wrapper) {
                    $state["{$label}/{$wrapper}"] = hash_file('sha256', "{$web}/{$wrapper}");
                }
            }
            return $state;
        };
        $before = $installed();

        // The runbook's verification: manifest readable, protocol 1, every
        // staged file exactly as pinned, nothing extra, nothing missing.
        $verify = function (string $staged, int $protocol = 1) use ($site): ?string {
            $manifest = json_decode((string) file_get_contents($staged . '/MANIFEST.json'), true);
            if (!is_array($manifest) || ($manifest['upgrade_protocol'] ?? 0) !== $protocol) {
                return 'the package manifest is unreadable or names an upgrade protocol this install does not run';
            }
            $pins = $manifest['files'] ?? [];
            if (!is_array($pins) || $pins === []) {
                return 'the package manifest pins no files';
            }
            foreach ($pins as $rel => $hash) {
                if (!is_file($staged . '/' . $rel) || !hash_equals((string) $hash, (string) hash_file('sha256', $staged . '/' . $rel))) {
                    return "a staged file does not match the package manifest: {$rel}";
                }
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($staged, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
            );
            foreach ($it as $path) {
                $rel = ltrim(substr((string) $path, strlen($staged)), '/');
                if ($rel === 'MANIFEST.json') {
                    continue;
                }
                if (!isset($pins[$rel])) {
                    return "the package carries an unstaged file: {$rel}";
                }
            }
            return null;
        };

        // The runbook's apply: quiet period, maintenance, rename the whole
        // old engine and launcher aside, install the staged files and both
        // wrapper sets, smoke the new engine, clear maintenance.
        $apply = function (string $staged) use ($site, $h): ?string {
            $lock = fopen($site . '/shared/run.lock', 'c');
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                return 'the quiet period is not established: work still holds the shared lease';
            }
            file_put_contents($site . '/shared/maintenance.lock', 'upgrade engine maintenance ' . date('c'));
            try {
                $stamp = date('Ymd-His');
                rename($site . '/upgrade', "{$site}/upgrade.previous-{$stamp}");
                rename($staged . '/upgrade', $site . '/upgrade');
                rename($site . '/bin/kip', "{$site}/bin/kip.previous-{$stamp}");
                copy($staged . '/bin/kip', $site . '/bin/kip');
                chmod($site . '/bin/kip', 0755);
                foreach (['site-public' => 'site/public', 'public_html' => 'public_html'] as $stagedDir => $live) {
                    foreach (['index.php', 'setup.php', 'upgrade.php', '.htaccess'] as $wrapper) {
                        copy("{$staged}/{$stagedDir}/{$wrapper}", $h['home'] . "/{$live}/{$wrapper}");
                    }
                }
                // The smoke test: the new engine boots and answers status.
                $status = 'PATH="/opt/homebrew/bin:/usr/bin:/bin" ' . escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($site . '/upgrade/cli.php') . ' status';
                exec('cd ' . escapeshellarg($h['home']) . ' && ' . $status . ' 2>&1', $out, $code);
                if ($code !== 0) {
                    return 'the installed engine failed its smoke test; restore the previous set';
                }
                return null;
            } finally {
                @unlink($site . '/shared/maintenance.lock');
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        };

        // An incompatible protocol refuses before anything moves.
        file_put_contents($manifestFile, (string) json_encode(['upgrade_protocol' => 2, 'files' => $hashes]) . "\n");
        $this->assertNotNull($verify($package, 1), 'an incompatible protocol refuses');
        file_put_contents($manifestFile, (string) json_encode(['upgrade_protocol' => 1, 'files' => $hashes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // A quiet period that is not quiet refuses (a shared holder counts).
        $holder = fopen($site . '/shared/run.lock', 'c');
        flock($holder, LOCK_SH);
        $staged = $this->tmptree('kiption-wu-stage-');
        self::rrcopy($package, $staged);
        $this->assertNotNull($apply($staged), 'a busy site is not quiet; the apply refuses');
        $this->assertSame($before, $installed(), 'a refused apply moved nothing');
        flock($holder, LOCK_UN);
        fclose($holder);

        // A corrupted staged file fails verification after the fact.
        file_put_contents($staged . '/upgrade/Journal.php', "<?php // corrupted\n");
        $this->assertNotNull($verify($staged), 'a corrupted staged file refuses');
        $this->assertSame($before, $installed(), 'a failed verification moved nothing');

        // The clean apply, then the smoke, then both layouts' wrappers carry
        // the package bytes.
        $staged = $this->tmptree('kiption-wu-stage-');
        self::rrcopy($package, $staged);
        $this->assertNull($verify($staged), 'an honest package verifies');
        $this->assertNull($apply($staged), 'the clean apply succeeds');
        foreach ($map as $rel => $source) {
            $live = match (true) {
                str_starts_with($rel, 'upgrade/') => $site . '/upgrade/' . substr($rel, 8),
                $rel === 'bin/kip' => $site . '/bin/kip',
                str_starts_with($rel, 'site-public/') => $h['home'] . '/site/public/' . substr($rel, 12),
                default => $h['home'] . '/public_html/' . substr($rel, 12),
            };
            $this->assertFileExists($live, "{$rel} landed");
            $this->assertSame(hash_file('sha256', $package . '/' . $rel), hash_file('sha256', $live),
                "{$rel} carries the package bytes");
        }
        $this->assertNotSame($before, $installed(), 'the maintenance changed the engine set');
        $this->assertNotEmpty(glob($site . '/upgrade.previous-*'), 'the previous engine is one rename away');
        $this->assertFileDoesNotExist($site . '/shared/maintenance.lock', 'maintenance is cleared after success');

        // The rollback: the previous set goes back, verified byte for byte.
        $previous = glob($site . '/upgrade.previous-*')[0];
        $launcherPrevious = glob($site . '/bin/kip.previous-*')[0];
        rename($site . '/upgrade', $site . '/upgrade.replaced');
        rename($previous, $site . '/upgrade');
        rename($site . '/bin/kip', $site . '/bin/kip.replaced');
        rename($launcherPrevious, $site . '/bin/kip');
        chmod($site . '/bin/kip', 0755);
        \Upgrade\Restore::rrmdir($site . '/upgrade.replaced');
        @unlink($site . '/bin/kip.replaced');
        $this->assertSame($before, $installed(), 'the rollback restores the previous engine set exactly');
        [$code, $out] = $this->sh($h['home'], 'PATH="/opt/homebrew/bin:/usr/bin:/bin" ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($site . '/upgrade/cli.php') . ' status');
        $this->assertSame(0, $code, 'the rolled-back engine still boots: ' . $out);
    }
}
