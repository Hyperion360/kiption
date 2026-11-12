<?php // app/src/SiteText/Boot.php
namespace App\SiteText;

use App\Lang;
use Kip\Database;

/**
 * Start-up for the language layer, shared by the web front controller and
 * bin/kip so both pick the same language. Two steps: early() before the
 * settings overlay (enough for the maintenance page), late() after it, when
 * the owner's saved default language is known.
 */
final class Boot
{
    /** Folder of compiled text under the private data directory. */
    public static function dir(array $config): string
    {
        return (string) ($config['site_text_dir'] ?? rtrim((string) ($config['data_dir'] ?? dirname(__DIR__, 3) . '/app'), '/') . '/site-text');
    }

    public static function early(array $config): void
    {
        Lang::useSiteText(self::dir($config));
        self::choose($config['ui_lang'] ?? 'en', null);
    }

    /** @param mixed $cookie the raw lang cookie, applied only when $cookieAllowed */
    public static function late(array $config, Database $db, mixed $cookie = null, bool $cookieAllowed = false): void
    {
        if (!Lang::siteTextReady()) {
            try {
                $db->one('SELECT 1 FROM site_text LIMIT 1'); // throws when the table is not migrated yet
                Compiler::compile($db, self::dir($config));
            } catch (\Throwable $e) {
                // an unmigrated or unwritable setup keeps the built-in text
            }
        }
        self::choose($config['ui_lang'] ?? 'en', $cookieAllowed ? $cookie : null);
    }

    /** The saved default if installed, else English; then the member's cookie if installed. */
    private static function choose(mixed $default, mixed $cookie): void
    {
        $installed = Lang::installed();
        $lang = is_string($default) && in_array($default, $installed, true) ? $default : 'en';
        if (is_string($cookie) && preg_match('/^[a-z]{2,3}$/', $cookie) === 1 && in_array($cookie, $installed, true)) {
            $lang = $cookie;
        }
        Lang::setCurrent($lang);
    }
}
