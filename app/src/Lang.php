<?php // app/src/Lang.php
namespace App;

/**
 * The UI language-pack layer (zero dependencies: plain PHP arrays, no gettext).
 *
 * Packs are flat ['dotted.key' => 'Text'] maps. app/lang/en.php is the base
 * inventory every pack merges OVER (array_replace; flat keys need no recursive
 * merge, finding 1), so a pack may override one string or all of them. Keys
 * missing from both the pack and en return the key itself: a bad key is
 * visible on the page, never fatal.
 *
 * Pack resolution for setCurrent('xx'): an explicit addPackPath('xx', file)
 * registration wins (third-party/test seam), else autodiscovery at
 * app/lang/xx.php. A missing file is an empty pack (pure en fallback); a file
 * that fails to load (parse error, thrown value) also degrades to en, with the
 * failure recorded in the error log.
 */
final class Lang
{
    private const DIR = __DIR__ . '/../lang';

    private static string $current = 'en';

    /** @var array<string, string> lang code => registered pack file (the override seam) */
    private static array $packPaths = [];

    /** @var array<string, array<string, string>> lang code => merged [key => text], memoized */
    private static array $cache = [];

    /** The active UI language. Codes outside [a-z]{2} coerce to en (soft, like a missing key). */
    public static function setCurrent(string $lang): void
    {
        self::$current = preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : 'en';
    }

    /** Register a pack file for a code, ahead of autodiscovery. Re-registration reloads. */
    public static function addPackPath(string $lang, string $file): void
    {
        self::$packPaths[$lang] = $file;
        unset(self::$cache[$lang]);
    }

    /** The active code, as validated by setCurrent (so always [a-z]{2} or en).
     *  Views emit it as <html lang> so screen readers pick the right voice. */
    public static function current(): string
    {
        return self::$current;
    }

    /** Translate a key under the current pack, interpolating {param} via strtr. */
    public static function t(string $key, array $params = []): string
    {
        $pack = self::all(self::$current);
        $text = $pack[$key] ?? $key;
        if ($params !== []) {
            $pairs = [];
            foreach ($params as $name => $value) {
                $pairs['{' . $name . '}'] = (string) $value;
            }
            $text = strtr($text, $pairs);
        }
        return $text;
    }

    /** The full merged map for a code: the pack's entries over en, memoized per lang. */
    public static function all(string $lang): array
    {
        if (isset(self::$cache[$lang])) return self::$cache[$lang];
        $en = self::load(self::DIR . '/en.php');
        if ($lang === 'en') return self::$cache[$lang] = $en;
        return self::$cache[$lang] = array_replace($en, self::load(
            self::$packPaths[$lang] ?? self::DIR . '/' . $lang . '.php'
        ));
    }

    /** @return array<string, string> an unloadable or non-file pack reads as empty (en underneath) */
    private static function load(string $file): array
    {
        if (!is_file($file)) return [];
        try {
            $pack = require $file;
        } catch (\Throwable $e) {
            error_log('Lang pack failed to load (' . $file . '): ' . $e->getMessage());
            return [];
        }
        return is_array($pack) ? $pack : [];
    }
}
