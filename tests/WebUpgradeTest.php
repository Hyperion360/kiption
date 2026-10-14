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
}
