<?php // app/src/Maintenance.php
declare(strict_types=1);

namespace App;

use Kip\{Database, Http\Request, Http\Response, Session};

/**
 * The owner's maintenance switch (/settings, setting maintenance_on). While on,
 * visitors get the 503 maintenance page and signed-in admins browse as usual,
 * with a banner reminding them. The sign-in pages stay open so an admin can
 * get in. This is separate from the hard lock the importer and upgrades use
 * (config 'maintenance'), which blocks everyone.
 */
final class Maintenance
{
    private static bool $on = false;

    public static function on(): bool
    {
        return self::$on;
    }

    public static function reset(): void
    {
        self::$on = false;
    }

    /** A 503 for a visitor while maintenance is on, else null. Called once per request, after the settings overlay. */
    public static function gate(array $config, Request $request, Session $session): ?Response
    {
        self::$on = ($config['maintenance_on'] ?? false) === true;
        if (!self::$on) return null;
        if (str_starts_with($request->path, '/auth/') || str_starts_with($request->path, '/assets/')) return null;
        if (($request->cookies ?? []) !== []) {
            try {
                $gate = Adminness::requireAdmin(new Database($config['db']['dsn']), $session);
                if (!($gate instanceof Response)) return null;
            } catch (\Throwable) {
                // an unreadable session reads as a visitor
            }
        }
        return MaintenanceGuard::response($config);
    }
}
