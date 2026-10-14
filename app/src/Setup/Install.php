<?php // app/src/Setup/Install.php
declare(strict_types=1);

namespace App\Setup;

use Kip\Auth;
use Kip\Database;
use Kip\Migrations\Migrator;
use RuntimeException;

/**
 * The one install service, shared by the CLI and the browser setup: the
 * migration inventory, the migration run, the blank starter archive, and the
 * atomic trusted-admin creation. It owns application state and nothing else
 * (no HTTP, no filesystem deployment logic); setup.php and bin/kip translate
 * their own input/output around it, so neither copies migration or admin SQL.
 */
final class Install
{
    /**
     * Feature folders (Kip guide ch. 3): migrations live in the app directory
     * AND in every app/Features/<Name>/migrations. Discovered lazily so a
     * broken Features layout never breaks logs/backup/help. scandir() over
     * the Features directory, never glob() (it reads app_dir as pattern
     * text, so a metacharacter path silently lists nothing). Anything that
     * EXISTS under a feature is passed through: Migrator::files() fails
     * loudly on a migrations path that is not a readable directory, instead
     * of quietly skipping it.
     *
     * @return list<string>
     */
    public static function migrationDirs(array $config): array
    {
        $dirs = [$config['app_dir'] . '/migrations'];
        // Honor the same override App.php reads for feature resolution, so
        // the CLI and the web app can never discover different feature trees
        // (adversarial review 2026-09-28).
        $featuresDir = $config['features_dir'] ?? $config['app_dir'] . '/Features';
        if (!is_dir($featuresDir) && !is_link($featuresDir) && !file_exists($featuresDir)) {
            return $dirs;
        }
        $entries = @scandir($featuresDir);
        if ($entries === false) {
            throw new RuntimeException("Cannot list {$featuresDir}: refusing to migrate a partial inventory as a complete batch.");
        }
        foreach ($entries as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $m = $featuresDir . '/' . $entry . '/migrations';
            if (is_dir($m) || is_link($m) || file_exists($m)) {
                $dirs[] = $m;
            }
        }
        return $dirs;
    }

    /**
     * Run the full migration inventory. Migrator commits each migration; a
     * failure mid-way leaves the applied prefix applied and a retry skips it.
     *
     * @return list<string> the migrations this run applied
     */
    public static function migrate(array $config): array
    {
        return (new Migrator(new Database((string) $config['db']['dsn']), self::migrationDirs($config)))->migrate();
    }

    /**
     * The blank starter archive: taxonomy (ratings ladder, tag types,
     * starter tags, one category, the content-policy page) plus the starter
     * nav. Idempotent; no demo content. Caller owns cache invalidation.
     */
    public static function blank(Database $db, array $config): void
    {
        \App\Seeder::bootstrap($db);
        // The comp's desktop nav (D3): Browse | Recent | Authors | Series |
        // Library as the operator's starting set (nav_links is operator-
        // managed after this; a reseed resets it so the header never carries
        // stale or duplicate links beside the shell's own Search affordances).
        $db->query('DELETE FROM nav_links');
        $db->query("INSERT INTO nav_links (label, url, position) VALUES
            ('Browse', '/browse', 1), ('Recent', '/browse/recent', 2),
            ('Authors', '/browse/authors', 3), ('Series', '/series', 4),
            ('Reading lists', '/lists', 5)");
        \App\NavLinks::rebuild($db, (string) ($config['nav_file'] ?? ''));
    }

    /**
     * The complete fresh install: validate, migrate, blank bootstrap, one
     * verified, approved admin. Safe to retry: applied migrations are
     * skipped, and an existing admin refuses the run outright.
     *
     * @param callable|null $afterRegister fault-injection seam for tests and
     *        recovery tooling: invoked with the connection after the admin
     *        INSERT but before promotion; throwing here proves the rollback
     *        leaves no partial user.
     */
    public static function fresh(array $config, string $email, string $password, ?callable $afterRegister = null): void
    {
        self::assertCandidate($email, $password);
        $db = new Database((string) $config['db']['dsn']);
        self::migrate($config);
        self::blank($db, $config);
        if (self::hasAdmin($db)) {
            throw new RuntimeException('An administrator already exists; refuse to create another.');
        }
        self::registerTrusted($db, $email, $password, true, $afterRegister);
    }

    /**
     * The atomic trusted-account primitive shared by `kip user:create` and
     * the browser setup: register, then verify/approve, then (optionally)
     * promote, in ONE transaction on ONE connection. Any failure rolls the
     * whole account back, so a crashed run never leaves an unverified or
     * unpromoted ghost behind.
     *
     * @param callable|null $afterRegister see fresh()
     */
    public static function registerTrusted(Database $db, string $email, string $password, bool $admin, ?callable $afterRegister = null): void
    {
        self::assertCandidate($email, $password);
        $db->begin();
        try {
            $session = []; // Session's array is by-reference; a CLI/setup run has no session state
            (new Auth($db, new \Kip\Session($session)))->register($email, $password);
            if ($afterRegister !== null) {
                $afterRegister($db);
            }
            // Phase-5 login gates: an admin-created account is trusted by
            // construction, so it is born verified and approved (no email
            // token exists for it).
            $db->query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?),
                        approved_at = COALESCE(approved_at, ?) WHERE email = ?', [date('c'), date('c'), $email]);
            if ($admin) {
                $db->query('UPDATE users SET is_admin = 1, role = \'admin\' WHERE email = ?', [$email]);
            }
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $db->commit();
    }

    /** True once any administrator exists; setup and fresh-install refuse after this. */
    public static function hasAdmin(Database $db): bool
    {
        try {
            return (int) $db->one('SELECT COUNT(*) c FROM users WHERE is_admin = 1')['c'] > 0;
        } catch (\Throwable) {
            return false; // unmigrated database: no admin can exist yet
        }
    }

    private static function assertCandidate(string $email, string $password): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Enter a valid admin email address.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('The password must be at least 8 characters.');
        }
    }
}
