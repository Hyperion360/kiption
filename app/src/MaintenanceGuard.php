<?php // app/src/MaintenanceGuard.php
namespace App;

final class MaintenanceGuard
{
    /** Pure decision from server-side config and the routed path; never
     *  consults query strings or cookies, so it cannot be bypassed by
     *  request crafting.
     *  Convention: maintenance_allow entries should end with '/' (e.g.
     *  '/admin/') so a prefix matches whole path segments; matching is
     *  plain str_starts_with, so '/admin' would also match '/adminxyz'. */
    public static function blocks(array $config, string $path): bool
    {
        if (!($config['maintenance'] ?? false)) {
            return false;
        }
        if (($config['env'] ?? 'prod') === 'dev') {
            return false;
        }
        foreach (($config['maintenance_allow'] ?? []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        return true;
    }
}
