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
        'validation_required' => 'bool', 'items_per_page' => 'int:1,100', 'powered_by' => 'bool', 'skin' => 'skin'];
    // powered_by: the board stores the row today; the override activates the
    // moment the app-prep footer lane ships the config key (overrides() skips
    // keys the config lacks, so the row waits harmlessly until then).

    /** @return array<string, string> raw DB rows, memoized with the flags pass */
    public static function all(): array { return Features::settings(); }

    /** Values to overlay onto $config at boot: only keys the DB actually holds, coerced to the config's own type. */
    public static function overrides(array $config): array
    {
        $out = [];
        foreach (self::all() as $k => $v) {
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

    private static function validate(string $key, string $value): void
    {
        [$kind, $rest] = array_pad(explode(':', self::KEYS[$key], 2), 2, '');
        switch ($kind) {
            case 'string':
                if ($value === '' || mb_strlen($value) > 60) throw new \InvalidArgumentException("{$key}: 1-60 characters");
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
