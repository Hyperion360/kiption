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
        mkdir($dst, 0775, true);
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
    private function startServer(int $port, string $docroot, string $router): mixed
    {
        $handle = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router],
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
     * it: verification reads bytes and hashes, never code.
     */
    private function writeFixtureTree(string $tree, string $version): void
    {
        foreach (['app/migrations', 'vendor/composer', 'public', 'bin'] as $dir) {
            mkdir($tree . '/' . $dir, 0775, true);
        }
        file_put_contents($tree . '/config.php', "<?php\nreturn [];\n");
        file_put_contents($tree . '/composer.json', "{\"require\":{\"php\":\"^8.3\"}}\n");
        file_put_contents($tree . '/composer.lock', "{\"packages\":[]}\n");
        file_put_contents($tree . '/vendor/autoload.php', "<?php // fixture autoloader\n");
        file_put_contents($tree . '/vendor/composer/installed.json', "{\"packages\":[]}\n");
        file_put_contents($tree . '/app/migrations/900_fixture.php', "<?php throw new \\Exception('staged code must never execute');\n");
        file_put_contents($tree . '/public/index.php', "<?php throw new \\Exception('staged code must never execute');\n");
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
    private function stageFixture(string $siteRoot, string $version, array $extra = []): array
    {
        $tree = $this->tmptree('kiption-wu-fixture-');
        $this->writeFixtureTree($tree, $version);
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
}
