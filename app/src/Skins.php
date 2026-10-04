<?php // app/src/Skins.php
declare(strict_types=1);

namespace App;

/**
 * Operator skins: a directory of view overrides under app/skins/{name}/views
 * that the framework's View layer consults BEFORE the app defaults,
 * template-by-template. This class is the boot seam both entrypoints share
 * (public/index.php and bin/kip): it turns the configured skin name into the
 * views_override dir, or '' whenever the skin is default, malformed, or has
 * no directory, so a bad value can never fatal a page. The name itself is
 * filesystem-inert by construction: only [a-z0-9-] survives validation, and
 * the seam re-checks the shape plus realpath containment before handing the
 * directory to the View.
 */
final class Skins
{
    public const DEFAULT = 'default';

    /** The skin name every untouched install uses: explicitly no override. */
    private const NAME = '/^[a-z0-9-]{1,30}$/';

    /** The views_override value for the boot config: the resolved skin views
     *  directory, or '' when the app defaults must serve everything. */
    public static function overrideDir(array $config): string
    {
        $skin = (string) ($config['skin'] ?? self::DEFAULT);
        if ($skin === self::DEFAULT || preg_match(self::NAME, $skin) !== 1) {
            return '';
        }
        $appDir = (string) ($config['app_dir'] ?? '');
        if ($appDir === '') {
            return '';
        }
        // realpath under app_dir/skins: symlinks resolve, and a directory
        // that does not exist reads as false (fall back to defaults).
        $skinsRoot = realpath(rtrim($appDir, '/') . '/skins');
        if ($skinsRoot === false) {
            return '';
        }
        $dir = realpath($skinsRoot . '/' . $skin . '/views');
        if ($dir === false || !is_dir($dir) || !str_starts_with($dir . '/', $skinsRoot . '/')) {
            return '';
        }
        return $dir;
    }

    /** The names the settings board offers: default first, then every
     *  installed skin (a directory under app/skins carrying views/).
     *  @return list<string> */
    public static function installed(array $config): array
    {
        $appDir = rtrim((string) ($config['app_dir'] ?? ''), '/');
        $names = [self::DEFAULT];
        $root = $appDir === '' ? false : realpath($appDir . '/skins');
        if ($root === false) {
            return $names;
        }
        // scandir, never glob: an app_dir containing metacharacters would
        // read as pattern text and silently list nothing (the bin/kip rule).
        foreach (scandir($root) ?: [] as $entry) {
            if (preg_match(self::NAME, $entry) !== 1) continue;
            if (is_dir($root . '/' . $entry . '/views')) $names[] = $entry;
        }
        sort($names); // alphabetical, with default's d landing naturally among them
        return array_values(array_unique($names));
    }
}
