<?php // app/src/Settings.php
declare(strict_types=1);

namespace App;

/**
 * Operator-editable settings, resolved in the SAME boot query as feature
 * flags (the query-budget contract: no new boot round trip). The board may
 * only write keys in KEYS; everything else stays config.php territory.
 */
final class Settings
{
    public const KEYS = ['site_name' => 'string', 'registration_mode' => 'oneof:open,verify,approval,invite',
        'validation_required' => 'bool', 'items_per_page' => 'int:1,100', 'powered_by' => 'bool', 'skin' => 'skin',
        'adult_rta' => 'label', 'ui_lang' => 'lang',
        'slogan' => 'text:120',
        'submissions_open' => 'bool', 'min_words' => 'int:0,100000', 'max_words' => 'int:0,1000000',
        'date_format' => 'oneof:short,long,dmy,iso', 'time_format' => 'oneof:12h,24h',
        'reviews_on' => 'bool', 'guest_reviews' => 'bool', 'rating_only' => 'bool', 'review_scores_on' => 'bool', 'categories_on' => 'bool',
        'coauthors_on' => 'bool', 'series_on' => 'bool',
        'tag_policy' => 'oneof:off,suggest,free',
        'default_sort' => 'oneof:recent,alpha', 'profiles_public' => 'bool', 'uploads_on' => 'bool', 'chapter_images_on' => 'bool',
        'footer_copyright' => 'label', 'footer_copyright_url' => 'link', 'footer_links' => 'links', 'og_image' => 'link', 'maintenance_on' => 'bool'];

    /** What each newer key means when no row exists (they have no config.php
     *  twin). Existing behavior is the default for every one of them. */
    public const DEFAULTS = [
        'slogan' => '', 'submissions_open' => '1', 'min_words' => '0', 'max_words' => '0',
        'date_format' => 'short', 'time_format' => '12h',
        'reviews_on' => '1', 'guest_reviews' => '1', 'rating_only' => '0', 'review_scores_on' => '1', 'categories_on' => '1',
        'coauthors_on' => '1', 'series_on' => '1',
        'tag_policy' => 'off',
        'default_sort' => 'recent', 'profiles_public' => '1', 'uploads_on' => '1', 'chapter_images_on' => '0',
    ];

    /** A new-style setting's effective value: the stored row, else its default.
     *  Reads the memo the boot query already filled; never queries. */
    public static function get(string $key): string
    {
        try { $row = self::all()[$key] ?? null; } catch (\Throwable) { $row = null; }
        return $row ?? self::DEFAULTS[$key] ?? '';
    }

    public static function on(string $key): bool { return self::get($key) === '1'; }

    // powered_by: the board stores the row today; the override activates the
    // moment the app-prep footer lane ships the config key (overrides() skips
    // keys the config lacks, so the row waits harmlessly until then).

    /** @return array<string, string> raw DB rows, memoized with the flags pass */
    public static function all(): array { return Features::settings(); }

    /** Values to overlay onto $config at boot: only keys the DB actually holds, coerced to the config's own type.
     *  A row outside KEYS (a stray or restored row named like a cap key) is skipped before the
     *  config is consulted: the board could never have written it, so it never wins. */
    public static function overrides(array $config): array
    {
        $out = [];
        foreach (self::all() as $k => $v) {
            if ($k === 'maintenance_on') { $out[$k] = ($v === '1'); continue; } // the owner's switch has no config.php key
            if (!\array_key_exists($k, self::KEYS)) continue; // never board-writable: the site administrator's cap keys stay config
            $current = $config[$k] ?? null;
            if ($current === null && !\in_array($k, ['skin'], true)) continue; // unknown keys stay config.php territory
            if ($current === true || $current === false) { $out[$k] = ($v === '1' || $v === 'on'); continue; }
            if (\is_int($current)) { $out[$k] = max(1, (int) $v); continue; }
            $out[$k] = (string) $v;
        }
        return $out;
    }

    /** The boot seam: DB settings layered over config, or the input unchanged on any failure
     *  (an unmigrated database must not fatal bin/kip doctor or bin/kip migrate: those arms
     *  exist precisely for broken installs). */
    public static function apply(array $config): array
    {
        try {
            return array_merge($config, self::overrides($config));
        } catch (\Throwable) {
            return $config; // flags/settings unreadable: run with config-file values
        }
    }

    public static function put(string $key, string $value): void
    {
        if (!\array_key_exists($key, self::KEYS)) throw new \InvalidArgumentException("unknown setting: {$key}");
        self::validate($key, $value);
        Features::putSetting($key, $value);
    }

    /** Would put() accept this value? Lets a form check every field before
     *  it writes any of them. */
    public static function accepts(string $key, string $value): bool
    {
        if (!\array_key_exists($key, self::KEYS)) return false;
        try { self::validate($key, $value); } catch (\InvalidArgumentException) { return false; }
        return true;
    }

    private static function validate(string $key, string $value): void
    {
        [$kind, $rest] = array_pad(explode(':', self::KEYS[$key], 2), 2, '');
        switch ($kind) {
            case 'string':
                if ($value === '' || mb_strlen($value) > 60) throw new \InvalidArgumentException("{$key}: 1-60 characters");
                break;
            case 'text':
                // Free text the owner may clear: empty, or up to N characters, no control characters.
                if (mb_strlen($value) > (int) $rest || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new \InvalidArgumentException("{$key}: empty, or up to {$rest} characters");
                }
                break;
            case 'label':
                // A label the operator can switch off: empty is a legal value
                // (adult_rta's off state), 1-60 characters otherwise.
                if ($value !== '' && mb_strlen($value) > 60) throw new \InvalidArgumentException("{$key}: empty, or 1-60 characters");
                break;
            case 'link':
                // Empty, an https address, or a path on this site (the footer's own stance).
                if ($value !== '' && (mb_strlen($value) > 300 || preg_match('/\s/', $value) === 1 || !FooterLegal::linkable($value))) {
                    throw new \InvalidArgumentException("{$key}: empty, https:// or /path");
                }
                break;
            case 'links':
                // A JSON list of at most ten {label, url} pairs (what the footer reads).
                $list = $value === '' ? [] : json_decode($value, true);
                if (!is_array($list) || count($list) > 10) throw new \InvalidArgumentException("{$key}: at most ten links");
                foreach ($list as $l) {
                    if (!is_array($l) || !is_string($l['label'] ?? null) || !is_string($l['url'] ?? null)
                        || $l['label'] === '' || mb_strlen($l['label']) > 40 || strlen($l['url']) > 300
                        || preg_match('/\s/', $l['url']) === 1 || !FooterLegal::linkable($l['url'])) {
                        throw new \InvalidArgumentException("{$key}: label and https:// or /path");
                    }
                }
                break;
            case 'lang':
                // The archive default: only a language that is installed right now.
                if (!in_array($value, Lang::installed(), true)) throw new \InvalidArgumentException("{$key}: not an installed language");
                break;
            case 'skin':
                if (!preg_match('/^[a-z0-9-]{1,30}$/', $value)) throw new \InvalidArgumentException('skin: letters, digits, dashes only');
                break;
            case 'oneof':
                if (!in_array($value, explode(',', $rest), true)) throw new \InvalidArgumentException("{$key}: unrecognized value");
                break;
            case 'bool':
                if (!in_array($value, ['0', '1'], true)) throw new \InvalidArgumentException("{$key}: 0 or 1");
                break;
            case 'int':
                [$min, $max] = array_pad(explode(',', $rest), 2, '0');
                if (!ctype_digit($value) || (int) $value < (int) $min || (int) $value > (int) $max) {
                    throw new \InvalidArgumentException("{$key}: {$min}-{$max}");
                }
                break;
        }
    }
}
