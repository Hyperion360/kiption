<?php // app/src/Setup/Preflight.php
declare(strict_types=1);

namespace App\Setup;

/**
 * The host compatibility probe, run before the browser setup offers to
 * install anything. It assesses the environment and mutates nothing that
 * belongs to the operator: the WAL probe is a throwaway database under the
 * actual data directory, removed with its sidecars when it is done.
 *
 * Doctor::run remains the source of the PHP, extension, and writable-path
 * verdicts (one report shape everywhere); this class adds the checks a
 * browser setup needs that a development doctor does not answer: a live WAL
 * round trip through two independent connections, private-file placement
 * outside the web root, the HTTPS posture of the configured URL, and the
 * rewrite smoke result the host matrix supplies (never a claim derived from
 * PHP alone, so it reports unverified until /__kiption-route-probe has been
 * served through the real host).
 */
final class Preflight
{
    /**
     * @param array<string,mixed> $config the app config (db, mail, static_cache, uploads, backups, base_url, app_dir, data_dir)
     * @param string $publicDir the web-served root
     * @param array<string,bool> $capabilities test seams and host-matrix
     *        verdicts: pdo_sqlite, wal, https, rewrite. A set capability
     *        replaces the derived/live result for that check.
     * @return array{ok:bool, checks:list<array{name:string, ok:bool, fix:string, status?:string}>}
     */
    public static function run(array $config, string $publicDir, array $capabilities = []): array
    {
        $checks = [];

        // Doctor owns the environment verdicts; preflight filters its report
        // down to what a fresh host needs and injects capability overrides.
        $doctor = \App\Doctor::run([
            'app_dir' => (string) ($config['app_dir'] ?? ''),
            'public_dir' => $publicDir,
            'config' => [
                'base_url' => (string) ($config['base_url'] ?? ''),
                'db' => ['dsn' => (string) ($config['db']['dsn'] ?? '')],
                'mail' => $config['mail'] ?? [],
                'backups' => $config['backups'] ?? [],
                'static_cache' => $config['static_cache'] ?? [],
                'uploads' => $config['uploads'] ?? [],
            ],
        ]);
        foreach ($doctor['checks'] as $check) {
            $name = (string) $check['name'];
            if ($name === 'migrations' || $name === 'search') {
                continue; // nothing is migrated yet on a fresh host; search mode is not an install blocker
            }
            if ($name === 'pdo_sqlite' && array_key_exists('pdo_sqlite', $capabilities)) {
                $check['ok'] = (bool) $capabilities['pdo_sqlite'];
            }
            $checks[] = ['name' => $name, 'ok' => (bool) $check['ok'], 'fix' => (string) $check['fix']];
        }

        $dataDir = (string) ($config['data_dir'] ?? $config['app_dir'] ?? '');
        $checks[] = self::writablePrivateData($dataDir);
        $checks[] = self::wal($dataDir, $capabilities['wal'] ?? null);
        $checks[] = self::privateRoot($dataDir, $publicDir);
        $checks[] = self::https((string) ($config['base_url'] ?? ''), $capabilities['https'] ?? null);
        $checks[] = self::rewrite($capabilities['rewrite'] ?? null);

        $failures = count(array_filter(
            $checks,
            static fn (array $c): bool => !$c['ok'] && ($c['status'] ?? '') !== 'unverified'
        ));
        return ['ok' => $failures === 0, 'checks' => $checks];
    }

    /** The setup-critical writable path: the private data directory itself. */
    private static function writablePrivateData(string $dataDir): array
    {
        $ok = $dataDir !== '' && (is_dir($dataDir) || @mkdir($dataDir, 0775, true) || is_dir($dataDir)) && is_writable($dataDir);
        return ['name' => 'writable private data', 'ok' => $ok, 'fix' => $ok ? '' :
            "create the private data directory and make it writable by the PHP user: mkdir -p {$dataDir} && chown <php-user> {$dataDir}"];
    }

    /** @return array{name:string, ok:bool, fix:string} */
    private static function wal(string $dataDir, ?bool $capability): array
    {
        if ($capability !== null) {
            return ['name' => 'wal', 'ok' => $capability, 'fix' => $capability ? '' :
                'this host\'s storage does not support SQLite WAL; Kiption needs a local persistent disk (NFS and most network filesystems fail this check)'];
        }
        $probe = $dataDir . '/preflight-wal-probe-' . bin2hex(random_bytes(4)) . '.sqlite';
        foreach ([$probe, $probe . '-wal', $probe . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
        try {
            if (!is_dir($dataDir) || !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
                return ['name' => 'wal', 'ok' => false,
                    'fix' => 'create the private data directory and make it writable by the PHP user: the WAL probe could not create a database there'];
            }
            $write = new \PDO('sqlite:' . $probe);
            $write->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            // Asking for WAL sets it and returns the resulting mode; storage
            // that cannot host WAL silently stays in its default mode.
            $mode = (string) $write->query('PRAGMA journal_mode=WAL')->fetchColumn();
            if (strtolower($mode) !== 'wal') {
                return ['name' => 'wal', 'ok' => false,
                    'fix' => "SQLite journal mode is {$mode}, not wal: this storage (often a network or oversold shared filesystem) cannot run Kiption"];
            }
            $write->exec('CREATE TABLE probe (v TEXT)');
            $write->exec("INSERT INTO probe (v) VALUES ('roundtrip')");
            $write->query('PRAGMA wal_checkpoint(PASSIVE)');
            // A second, independent connection must read what the first wrote.
            $read = new \PDO('sqlite:' . $probe);
            $read->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $seen = (string) $read->query('SELECT v FROM probe')->fetchColumn();
            if ($seen !== 'roundtrip') {
                return ['name' => 'wal', 'ok' => false,
                    'fix' => 'a second connection could not read what the first wrote through WAL; the storage is not safely concurrent'];
            }
            return ['name' => 'wal', 'ok' => true, 'fix' => ''];
        } catch (\Throwable $e) {
            return ['name' => 'wal', 'ok' => false,
                'fix' => 'the WAL probe failed (' . $e->getMessage() . '); Kiption needs a writable local disk where SQLite WAL works'];
        } finally {
            foreach ([$probe, $probe . '-wal', $probe . '-shm'] as $sidecar) {
                if (is_file($sidecar)) {
                    @unlink($sidecar);
                }
            }
        }
    }

    /** @return array{name:string, ok:bool, fix:string} */
    private static function privateRoot(string $dataDir, string $publicDir): array
    {
        $data = realpath($dataDir);
        $public = realpath($publicDir);
        if ($data === false || $public === false) {
            return ['name' => 'private_root', 'ok' => false,
                'fix' => 'both the private data directory and the public directory must exist before preflight'];
        }
        if (str_starts_with($data . '/', $public . '/')) {
            return ['name' => 'private_root', 'ok' => false,
                'fix' => 'the private data directory sits inside the web root; move it outside the document root so databases and setup files are never fetchable'];
        }
        return ['name' => 'private_root', 'ok' => true, 'fix' => ''];
    }

    /** @return array{name:string, ok:bool, fix:string, status?:string} */
    private static function https(string $baseUrl, ?bool $capability): array
    {
        if ($capability !== null) {
            return ['name' => 'https', 'ok' => $capability, 'fix' => $capability ? '' : 'serve the archive over HTTPS'];
        }
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if (($parts['scheme'] ?? '') === 'https') {
            return ['name' => 'https', 'ok' => true, 'fix' => ''];
        }
        if ($loopback) {
            // Development default: the browser setup refuses it for a real install anyway.
            return ['name' => 'https', 'ok' => true, 'fix' => ''];
        }
        return ['name' => 'https', 'ok' => false,
            'fix' => 'the archive URL is plain HTTP; setup records a canonical HTTPS URL, so enable HTTPS on the host'];
    }

    /** @return array{name:string, ok:bool, fix:string, status?:string} */
    private static function rewrite(?bool $capability): array
    {
        if ($capability === true) {
            return ['name' => 'rewrite', 'ok' => true, 'fix' => ''];
        }
        return ['name' => 'rewrite', 'ok' => false, 'status' => 'unverified',
            'fix' => 'not yet verified: confirm /__kiption-route-probe serves through the host\'s rewrite before going live'];
    }
}
