<?php // tests/SharedHostReleaseTest.php
namespace App\Tests;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * The shared-host release contract: one locked-Composer build produces an
 * install ZIP (site/ + public_html/) and a release-root upgrade ZIP, both
 * authenticated by one RELEASE.json manifest whose SHA-256 IS the release ID,
 * plus whole-ZIP checksum sidecars for the download page. The stable CLI
 * launcher (site/bin/kip, sourced from upgrade/dispatch.php) dispatches to
 * whichever release site/current names, without application bootstrap.
 */
final class SharedHostReleaseTest extends TestCase
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
                throw new RuntimeException("symlink in source: {$s}");
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

    /** @return array<string,array{name:string,size:int}> entry name => stat, by name */
    private static function zipEntries(string $zipPath): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CHECKCONS) === true, "cannot open {$zipPath}");
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (array) $zip->statIndex($i);
        }
        $zip->close();
        return $entries;
    }

    private static function zipRead(string $zipPath, string $entry): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        $index = $zip->locateName($entry);
        self::assertNotFalse($index, "missing entry {$entry} in {$zipPath}");
        $bytes = (string) $zip->getFromIndex($index);
        $zip->close();
        return $bytes;
    }

    private static function assertNoSymlinkEntries(string $zipPath): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                $format = (($attr >> 16) & 0xF000);
                self::assertNotSame(0xA000, $format, "entry {$zip->getNameIndex($i)} is a symlink");
            }
        }
        $zip->close();
    }

    /**
     * A disposable source copy for the builder: the real tree minus .git and
     * vendor (the builder re-installs the locked graph itself). Deliberately
     * keeps development files, databases, and cache artifacts on disk so the
     * allowlist and exclusion rules are exercised against real clutter.
     */
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

    // ------------------------------------------------------- Task 1: build

    public function test_release_manifest(): void
    {
        $repo = dirname(__DIR__);
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);

        $home = $this->tmptree('kiption-rel-');
        $copy = $this->sourceCopy($home);
        $install = $home . '/kiption-install.zip';
        $upgrade = $home . '/kiption-upgrade.zip';

        [$code, $out] = $this->build($copy, '--development --allow-dirty --version=0.1.1-test'
            . ' --git-sha=' . $sha
            . ' --output=' . escapeshellarg($install)
            . ' --upgrade-output=' . escapeshellarg($upgrade));
        $this->assertSame(0, $code, $out);

        // Sidecars: one manifest digest, one whole-ZIP checksum per artifact.
        foreach ([$install, $upgrade] as $zipPath) {
            $this->assertFileExists($zipPath);
            $this->assertFileExists($zipPath . '.sha256');
        }
        $this->assertFileExists($install . '.manifest.sha256');

        // Release ID: the SHA-256 of the exact RELEASE.json bytes.
        $id = trim(self::zipRead($install, 'site/current'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id, 'site/current names a 64-hex release ID');
        $manifest = self::zipRead($install, 'site/releases/' . $id . '/RELEASE.json');
        $this->assertSame($id, hash('sha256', $manifest), 'the release ID hashes the exact manifest bytes');
        $this->assertSame($manifest, self::zipRead($upgrade, 'RELEASE.json'), 'manifest bytes identical in both ZIPs');
        $this->assertStringContainsString($id, (string) file_get_contents($install . '.manifest.sha256'));

        // Whole-ZIP checksums cover the exact bytes, stable wrappers included.
        $this->assertSame(hash('sha256', (string) file_get_contents($install)) . "\n", (string) file_get_contents($install . '.sha256'));
        $this->assertSame(hash('sha256', (string) file_get_contents($upgrade)) . "\n", (string) file_get_contents($upgrade . '.sha256'));

        // Altering one stable wrapper breaks the install checksum.
        $tampered = $home . '/tampered.zip';
        copy($install, $tampered);
        $wrapper = $home . '/wrapper.php';
        file_put_contents($wrapper, "<?php // altered\n");
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tampered) === true);
        $zip->addFile($wrapper, 'site/public/index.php');
        $zip->close();
        $this->assertNotSame(
            hash('sha256', (string) file_get_contents($tampered)),
            trim((string) file_get_contents($install . '.sha256')),
            'an altered stable wrapper must fail the whole-ZIP checksum'
        );

        // Install layout: versioned release under site/releases/<id>/, stable
        // entry points beside it, fixed-docroot wrapper, empty shared dir.
        $entries = self::zipEntries($install);
        $prefix = 'site/releases/' . $id . '/';
        foreach ([
            $prefix . 'vendor/autoload.php',
            $prefix . 'vendor/composer/installed.json',
            $prefix . 'vendor/kip/framework/composer.json',
            $prefix . 'config.php',
            $prefix . 'composer.lock',
            $prefix . 'bin/kip',
            $prefix . 'RELEASE.json',
            'site/public/index.php',
            'site/public/.htaccess',
            'site/public/assets/app.js',
            'site/bin/kip',
            'public_html/index.php',
            'public_html/.htaccess',
            'public_html/assets/app.js',
            'site/shared/',
        ] as $need) {
            $this->assertArrayHasKey($need, $entries, "install ZIP lacks {$need}");
        }

        // The locked Kip package: resolved from built metadata, inside vendor/.
        $installed = json_decode(self::zipRead($install, $prefix . 'vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $packages = $installed['packages'] ?? $installed;
        $framework = array_values(array_filter($packages, static fn (array $p): bool => ($p['name'] ?? '') === 'kip/framework'));
        $this->assertCount(1, $framework, 'exactly one locked kip/framework');
        $lock = json_decode((string) file_get_contents($copy . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $lockedVersion = '';
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $p) {
            if (($p['name'] ?? '') === 'kip/framework') {
                $lockedVersion = (string) ($p['version'] ?? '');
            }
        }
        $this->assertSame($lockedVersion, (string) $framework[0]['version'], 'bundled framework matches composer.lock');

        // Migrations ride along (a release without schema cannot install).
        $migrationCount = 0;
        foreach (array_keys($entries) as $name) {
            if (str_starts_with($name, $prefix . 'app/migrations/') && str_ends_with($name, '.php')) {
                $migrationCount++;
            }
        }
        $this->assertGreaterThan(0, $migrationCount, 'release carries migrations');

        // Upgrade ZIP: release payload directly at the root, no site/ prefix.
        $upEntries = self::zipEntries($upgrade);
        foreach (['RELEASE.json', 'config.php', 'composer.lock', 'bin/kip'] as $need) {
            $this->assertArrayHasKey($need, $upEntries, "upgrade ZIP lacks {$need}");
        }
        $this->assertArrayHasKey('vendor/autoload.php', $upEntries);
        $this->assertArrayHasKey('public/index.php', $upEntries);
        $this->assertArrayHasKey('public/.htaccess', $upEntries);
        foreach (array_keys($upEntries) as $name) {
            $this->assertStringNotContainsString('site/', $name, 'upgrade payload has no site/ prefix');
        }

        // Manifest schema and lock pin.
        $meta = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.1-test', $meta['version']);
        $this->assertSame($sha, $meta['git_sha']);
        $this->assertSame(hash('sha256', (string) file_get_contents($copy . '/composer.lock')), $meta['lock_hash']);
        $this->assertSame(1, $meta['upgrade_protocol']);
        $this->assertTrue($meta['development_bundle'], 'the development marker is stamped');
        $this->assertSame(PHP_VERSION, $meta['php_version']);
        $this->assertArrayNotHasKey('id', $meta, 'the manifest never carries its own hash');
        $this->assertArrayHasKey('files', $meta);
        $this->assertNotEmpty($meta['files']);

        // No development clutter, databases, secrets, or symlinks anywhere.
        // Vendor packages legitimately ship their own tests/docs/changelogs;
        // the release-root allowlist (asserted by the checker) pins ours.
        $forbidden = '/\.sqlite(-wal|-shm)?$|\.log$|phpunit\.xml|setup-token|deployment\.php|DBMAP\.md|SKINS\.md|TODOS\.md/';
        $devClutter = '/(^|\/)(\.git|tests|scripts|docs|changelog\.d)(\/|$)|(^|\/)upgrade(\/|$)/';
        foreach ([$entries, $upEntries] as $set) {
            foreach (array_keys($set) as $name) {
                $this->assertDoesNotMatchRegularExpression($forbidden, $name, "forbidden entry {$name}");
                if (preg_match('#(^|/)vendor/#', $name) !== 1) {
                    $this->assertDoesNotMatchRegularExpression($devClutter, $name, "forbidden entry {$name}");
                }
            }
        }
        $this->assertNoSymlinkEntries($install);
        $this->assertNoSymlinkEntries($upgrade);

        // The checker accepts the development bundle and rejects it as production.
        $check = 'PATH="/opt/homebrew/bin:/usr/bin:/bin:$PATH" %s scripts/check-release.php %s --zip=%s --upgrade-zip=%s';
        [$c2, $o2] = $this->sh($copy, sprintf($check, escapeshellarg(PHP_BINARY), '--development', escapeshellarg($install), escapeshellarg($upgrade)));
        $this->assertSame(0, $c2, $o2);
        [$c3, $o3] = $this->sh($copy, sprintf($check, escapeshellarg(PHP_BINARY), '', escapeshellarg($install), escapeshellarg($upgrade)));
        $this->assertNotSame(0, $c3, 'production check must reject a development bundle: ' . $o3);

        // Missing or malformed --version fails the builder outright.
        [$c4] = $this->build($copy, '--development --allow-dirty --git-sha=' . $sha . ' --output=' . escapeshellarg($home . '/x.zip'));
        $this->assertNotSame(0, $c4, 'builder requires an explicit version');
        [$c5] = $this->build($copy, '--development --allow-dirty --version=banana --output=' . escapeshellarg($home . '/x.zip'));
        $this->assertNotSame(0, $c5, 'builder rejects a malformed version');
    }

    /** A dirty source tree without --allow-dirty is refused, and the Git SHA is read from the repo. */
    public function test_builder_rejects_dirty_tree_without_flag(): void
    {
        $home = $this->tmptree('kiption-dirty-');
        $copy = $this->sourceCopy($home);
        // A real throwaway repo so dirty-state detection and SHA recording run.
        $this->sh($copy, 'git init -q && git config user.email t@e.test && git config user.name t && git add -A && git commit -qm seed');
        $this->sh($copy, 'echo dirt >> README.md');

        [$code, $out] = $this->build($copy, '--development --version=0.1.1-test --output=' . escapeshellarg($home . '/x.zip'));
        $this->assertNotSame(0, $code, 'dirty tree without --allow-dirty must fail: ' . $out);

        [$code2, $out2] = $this->build($copy, '--development --allow-dirty --version=0.1.1-test --output=' . escapeshellarg($home . '/x.zip'));
        $this->assertSame(0, $code2, $out2);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($home . '/x.zip') === true);
        $id = trim((string) $zip->getFromName('site/current'));
        $zip->close();
        $meta = json_decode(self::zipRead($home . '/x.zip', 'site/releases/' . $id . '/RELEASE.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = trim((string) shell_exec('git -C ' . escapeshellarg($copy) . ' rev-parse HEAD'));
        $this->assertSame($expected, $meta['git_sha'], 'the builder records the source Git SHA itself');
    }

    // ---------------------------------------------- stable CLI dispatching

    /**
     * Stub releases prove the stable site/bin/kip wrapper resolves
     * site/current per invocation without any application bootstrap, holds
     * the shared/run.lock lease, refuses serve, gates on maintenance before
     * dispatch, and makes import:efiction --commit an exclusive,
     * maintenance-marked writer whose success is the only thing that lifts
     * the marker.
     */
    public function test_stable_cli_wrapper(): void
    {
        $home = $this->tmptree('kiption-cli-');
        $site = $home . '/site';
        mkdir($site . '/bin', 0775, true);
        mkdir($site . '/shared', 0775, true);
        mkdir($site . '/releases', 0775, true);
        copy(dirname(__DIR__) . '/upgrade/dispatch.php', $site . '/bin/kip');
        chmod($site . '/bin/kip', 0755);

        $makeRelease = function (string $id, string $token) use ($site): void {
            $dir = $site . '/releases/' . $id;
            mkdir($dir . '/bin', 0775, true);
            $script = "<?php\n"
                . "if ((\$argv[1] ?? '') === 'import:efiction') {\n"
                . "    \$marker = dirname(dirname(dirname(__DIR__))) . '/shared/maintenance.lock';\n"
                . "    fwrite(STDERR, 'STUB-{$token}:marker=' . (is_file(\$marker) ? 'yes' : 'no'));\n"
                . "    \$code = getenv('STUB_EXIT');\n"
                . "    if (\$code !== false && \$code !== '0') exit((int) \$code); // failure: the process dies, maintenance stays\n"
                . "    return; // success: the versioned command returns, the launcher lifts maintenance\n"
                . "}\n"
                . "echo 'STUB-{$token}:' . implode(',', array_slice(\$argv, 1));\n";
            file_put_contents($dir . '/bin/kip', $script);
        };
        $idA = str_repeat('a', 64);
        $idB = str_repeat('b', 64);
        $makeRelease($idA, 'A');
        $makeRelease($idB, 'B');
        $current = $site . '/current';
        $kip = 'PATH="/opt/homebrew/bin:/usr/bin:/bin:$PATH" ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($site . '/bin/kip');

        // Dispatch follows the pointer, no application code involved.
        file_put_contents($current, $idA . "\n");
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertSame(0, $code, $out);
        $this->assertSame('STUB-A:version', $out);
        file_put_contents($current, $idB);
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertSame('STUB-B:version', $out);
        $this->assertSame(0, $code, $out);

        // A missing or malformed pointer is a controlled refusal, not a path leak.
        file_put_contents($current, "../../etc\n");
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('/', $out);
        unlink($current);
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString('/', $out);
        file_put_contents($current, $idA);

        // serve is refused through the stable wrapper (it would hold a shared
        // lease forever; VPS operators use a supervised dev process).
        [$code, $out] = $this->sh($home, $kip . ' serve');
        $this->assertNotSame(0, $code, 'serve must be refused');
        $this->assertStringNotContainsString('STUB-A', $out);

        // import:efiction flags are validated before any lock or dispatch.
        foreach (['', '--dry-run --commit'] as $flags) {
            [$code, $out] = $this->sh($home, $kip . " import:efiction /tmp/nope.tar.gz {$flags}");
            $this->assertNotSame(0, $code, "flags [{$flags}] must fail before dispatch");
            $this->assertStringNotContainsString('STUB', $out);
            $this->assertFileDoesNotExist($site . '/shared/maintenance.lock');
        }

        // Committed import: exclusive lease, maintenance set before dispatch,
        // cleared only by success.
        [$code, $out] = $this->sh($home, $kip . ' import:efiction /tmp/nope.tar.gz --commit');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('STUB-A:marker=yes', $out, 'maintenance is active inside the committed import');
        $this->assertFileDoesNotExist($site . '/shared/maintenance.lock', 'success clears maintenance');
        $this->assertFileExists($site . '/shared/run.lock', 'the lease file persists; the OS lock does not');

        putenv('STUB_EXIT=3');
        [$code, $out] = $this->sh($home, $kip . ' import:efiction /tmp/nope.tar.gz --commit');
        putenv('STUB_EXIT');
        $this->assertSame(3, $code, $out);
        $this->assertFileExists($site . '/shared/maintenance.lock', 'a failed committed import stays in maintenance for recovery');
        unlink($site . '/shared/maintenance.lock');

        // Dry-run is an ordinary shared-lease command: no maintenance ever.
        [$code, $out] = $this->sh($home, $kip . ' import:efiction /tmp/nope.tar.gz --dry-run');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('STUB-A:marker=no', $out);
        $this->assertFileDoesNotExist($site . '/shared/maintenance.lock');

        // With maintenance active, ordinary commands refuse before dispatch.
        touch($site . '/shared/maintenance.lock');
        [$code, $out] = $this->sh($home, $kip . ' migrate');
        $this->assertNotSame(0, $code, 'ordinary commands refuse during maintenance');
        $this->assertStringNotContainsString('STUB-A', $out);
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertSame(0, $code, 'version stays available during maintenance: ' . $out);
        unlink($site . '/shared/maintenance.lock');

        // The lease is real: an outside LOCK_EX holder blocks ordinary
        // commands (bounded wait) and outright blocks a committed import.
        $fp = fopen($site . '/shared/run.lock', 'c');
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));
        putenv('KIPTION_RUN_LOCK_TIMEOUT=1');
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertNotSame(0, $code, 'ordinary command waits out the exclusive holder then refuses');
        $this->assertStringNotContainsString('STUB-A', $out);
        [$code, $out] = $this->sh($home, $kip . ' import:efiction /tmp/nope.tar.gz --commit');
        $this->assertNotSame(0, $code, 'committed import never waits out another writer');
        $this->assertStringNotContainsString('STUB-A', $out);
        putenv('KIPTION_RUN_LOCK_TIMEOUT');
        flock($fp, LOCK_UN);
        fclose($fp);

        // A killed worker releases the OS lock: the next command proceeds
        // (the file left behind is never the lock).
        $fp = fopen($site . '/shared/run.lock', 'c');
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));
        fclose($fp); // closing the last handle drops the lock, as a killed worker would
        [$code, $out] = $this->sh($home, $kip . ' version');
        $this->assertSame(0, $code, $out);
    }
}
