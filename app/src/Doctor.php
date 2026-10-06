<?php // app/src/Doctor.php
declare(strict_types=1);

namespace App;

/**
 * The CLI-era installer safety net: everything eFiction's browser wizard
 * checked, checked from `bin/kip doctor` instead. Every check carries its
 * fix, so the output is actionable, not just diagnostic. Pure function of
 * its input array: no service locators, trivially testable.
 */
final class Doctor
{
    /** @param array{app_dir:string, public_dir:string, config:array, expected_migrations?:int} $env */
    public static function run(array $env): array
    {
        $c = $env['config'];
        $checks = [
            self::check('php', PHP_VERSION_ID >= 80300, 'PHP 8.3+ required, running ' . PHP_VERSION,
                'install PHP 8.3 or newer (Homebrew: brew install php@8.3)'),
            self::check('pdo_sqlite', extension_loaded('pdo_sqlite'), 'pdo_sqlite extension loaded', 'install the php-sqlite3 package for your PHP build'),
            self::check('search', true, 'search mode: ' . self::searchMode(), self::searchMode() === 'fts5'
                ? '' : 'your PHP lacks FTS5; search falls back to LIKE (slower, no stemming); rebuild php with FTS5 or accept the fallback'),
            self::check('base_url', preg_match('#^https?://\w#', (string) ($c['base_url'] ?? '')) === 1,
                'base_url is an absolute URL', 'set base_url in config.php; emails and feeds link through it'),
            self::check('mail', in_array($c['mail']['transport'] ?? '', ['log', 'mail', 'smtp'], true),
                'mail transport: ' . ($c['mail']['transport'] ?? '(unset)'),
                ($c['mail']['transport'] ?? '') === 'smtp'
                    ? 'smtp needs host/port/username/password keys under mail in config.php'
                    : 'transport log writes to app/mail.log; verification and password reset need smtp or mail before real users arrive'),
            self::check('migrations', self::migrationsApplied($env) >= ($env['expected_migrations'] ?? 0),
                'migrations applied: ' . self::migrationsApplied($env) . ' of ' . ($env['expected_migrations'] ?? 0) . ' known',
                'run: php bin/kip migrate'),
        ];
        foreach ([
            'data dir' => $env['app_dir'],
            'static cache' => $c['static_cache']['dir'] ?? '',
            'uploads' => $c['uploads']['dir'] ?? '',
            'backups' => $c['backups']['dir'] ?? '',
        ] as $label => $dir) {
            $ok = $dir !== '' && (is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir);
            $checks[] = self::check('writable ' . $label, $ok, "{$label} writable ({$dir})",
                "make it writable by the PHP user: mkdir -p {$dir} && chown <php-user> {$dir}");
        }
        $failures = count(array_filter($checks, fn($x) => !$x['ok']));
        return ['ok' => $failures === 0, 'failures' => $failures, 'search_mode' => self::searchMode(), 'checks' => $checks];
    }

    public static function runJson(array $env): string
    {
        return (string) json_encode(self::run($env), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function migrationsApplied(array $env): int
    {
        // is_file FIRST: a diagnostic must never create the database it is checking.
        // The configured DSN wins (tests and scripted installs point it elsewhere);
        // the app_dir default matches a stock config.php install.
        $file = self::sqliteFile((string) ($env['config']['db']['dsn'] ?? ''));
        if ($file === null) $file = $env['app_dir'] . '/data.sqlite';
        if (!is_file($file)) return 0;
        try {
            return (int) (new \PDO('sqlite:' . $file))
                ->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
        } catch (\Throwable) {
            return 0; // unmigrated or not-yet-migrated database: 0 rows applied
        }
    }

    /** The file behind a sqlite: DSN (query params stripped), or null for memory/none. */
    private static function sqliteFile(string $dsn): ?string
    {
        if ($dsn === '' || !str_starts_with($dsn, 'sqlite:')) return null;
        $file = explode('?', substr($dsn, strlen('sqlite:')), 2)[0];
        return $file === '' || $file === ':memory:' ? null : $file;
    }

    private static function searchMode(): string
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE VIRTUAL TABLE probe USING fts5(body)');
            return 'fts5';
        } catch (\Throwable) {
            return 'like';
        }
    }

    private static function check(string $name, bool $ok, string $summary, string $fix): array
    {
        return ['name' => $name, 'ok' => $ok, 'summary' => $summary, 'fix' => $fix];
    }
}
