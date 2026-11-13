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
 *
 * A pack may declare '_dir' => 'rtl' (the reserved metadata key; string-keyed
 * t() lookups can never hit it) to mark itself right-to-left; dir() reads it
 * and views emit the document direction from it.
 */
final class Lang
{
    private const DIR = __DIR__ . '/../lang';

    private static string $current = 'en';

    /** @var array<string, string> lang code => registered pack file (the override seam) */
    private static array $packPaths = [];

    /** @var array<string, array<string, string>> lang code => merged [key => text], memoized */
    private static array $cache = [];

    /** @var array<string, string>|null the shipped en.php, memoized */
    private static ?array $base = null;

    /** @var array<int, string>|null the app/lang pack basenames, memoized */
    private static ?array $installed = null;

    /** @var string|null the folder of compiled owner text (see SiteText\Compiler) */
    private static ?string $siteDir = null;

    /** @var array<string, array{name: string, dir: string}>|null owner-added languages, memoized */
    private static ?array $siteIndex = null;

    /** True for a well-formed language code: two or three lowercase letters.
     *  Codes name files on disk, so nothing else may pass. */
    public static function validCode(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[a-z]{2,3}$/', $code) === 1;
    }

    /** The active UI language. Codes that fail validCode() coerce to en (soft, like a missing key). */
    public static function setCurrent(string $lang): void
    {
        self::$current = self::validCode($lang) ? $lang : 'en';
    }

    /** Point the layer at the folder of compiled owner text (null turns it off). Clears the caches. */
    public static function useSiteText(?string $dir): void
    {
        self::$siteDir = $dir;
        self::$cache = [];
        self::$siteIndex = null;
        self::$installed = null;
    }

    /** Forget everything loaded and the site text folder (tests). */
    public static function reset(): void
    {
        self::$siteDir = null;
        self::$cache = [];
        self::$siteIndex = null;
        self::$installed = null;
        self::$packPaths = [];
        self::$current = 'en';
    }

    /** Has the compiled folder been built yet? */
    public static function siteTextReady(): bool
    {
        return self::$siteDir !== null && is_file(self::$siteDir . '/index.php');
    }

    /** Owner-added languages: code => name and dir, from the compiled index. */
    private static function siteIndex(): array
    {
        if (self::$siteIndex !== null) return self::$siteIndex;
        $index = [];
        if (self::$siteDir !== null) {
            foreach (self::load(self::$siteDir . '/index.php') as $code => $meta) {
                if (self::validCode($code) && is_array($meta)) {
                    $index[$code] = [
                        'name' => is_string($meta['name'] ?? null) ? $meta['name'] : $code,
                        'dir' => ($meta['dir'] ?? '') === 'rtl' ? 'rtl' : 'ltr',
                    ];
                }
            }
        }
        return self::$siteIndex = $index;
    }

    /** Codes that ship as pack files in app/lang. */
    public static function builtinCodes(): array
    {
        $codes = [];
        foreach (glob(self::DIR . '/*.php') ?: [] as $file) {
            $codes[] = basename($file, '.php');
        }
        sort($codes);
        return $codes;
    }

    /** The language's display name: owner name, the pack's _name, English, else the code. */
    public static function name(string $code): string
    {
        $owned = self::siteIndex()[$code]['name'] ?? null;
        if ($owned !== null) return $owned;
        $pack = self::builtin($code)['_name'] ?? null;
        if (is_string($pack) && $pack !== '') return $pack;
        return $code === 'en' ? 'English' : $code;
    }

    /** en overlaid with the shipped pack for a code, without any owner text. */
    public static function builtin(string $lang): array
    {
        $en = self::base();
        if ($lang === 'en') return $en;
        return array_replace($en, self::load(self::$packPaths[$lang] ?? self::DIR . '/' . $lang . '.php'));
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

    /** The active pack's declared direction: 'rtl' when the pack carries the
     *  reserved metadata key '_dir' => 'rtl', null otherwise (ltr, the
     *  default). Pack files are operator-installed code, the same trust level
     *  as config, so this never reads member data; anything but a literal
     *  'rtl' reads as ltr. Views emit it as <html dir> next to lang.
     *  '_dir' rides the merged map harmlessly: t() lookups are string keys,
     *  and no view ever asks for a key named '_dir'. */
    public static function dir(): ?string
    {
        return (self::all(self::$current)['_dir'] ?? null) === 'rtl' ? 'rtl' : null;
    }

    /** The installed pack codes: app/lang/*.php basenames, memoized. The
     *  prefs form's select options and the prefs save's lang validation share
     *  this list; addPackPath registrations (tests, third parties) are not
     *  files in app/lang and stay off it. */
    public static function installed(): array
    {
        if (self::$installed !== null) return self::$installed;
        $codes = array_values(array_unique(array_merge(self::builtinCodes(), array_keys(self::siteIndex()))));
        sort($codes);
        return self::$installed = $codes;
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

    /** The shipped English pack alone: the built-in inventory and the source of
     *  truth for which keys exist. Owner text never changes it. */
    public static function base(): array
    {
        return self::$base ??= self::load(self::DIR . '/en.php');
    }

    /** The full merged map for a code: the pack's entries over en, memoized per lang. */
    public static function all(string $lang): array
    {
        if (isset(self::$cache[$lang])) return self::$cache[$lang];
        $map = self::builtin($lang);
        if (self::$siteDir !== null && self::validCode($lang)) {
            $map = array_replace($map, self::load(self::$siteDir . '/' . $lang . '.php'));
        }
        return self::$cache[$lang] = $map;
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
