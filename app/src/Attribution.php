<?php // app/src/Attribution.php
namespace App;

/**
 * The "Powered by Kiption" footer seam (the Features/Lang precedent: init
 * once with the config at bootstrap, static reads everywhere else, because
 * layout templates have no config access and every render path, web or
 * CLI, shares the static). Uninitialized reads the default: attribution
 * ON, product site https://kiption.cloud, exactly what an entrypoint that
 * somehow skips init rendered before the switch existed.
 */
final class Attribution
{
    private static bool $on = true;
    private static string $url = 'https://kiption.cloud';

    /** Capture the footer keys from the boot config; re-init re-reads. */
    public static function init(array $config): void
    {
        self::$on = (bool) ($config['powered_by'] ?? true);
        $url = $config['powered_by_url'] ?? 'https://kiption.cloud';
        self::$url = is_string($url) && $url !== '' ? $url : 'https://kiption.cloud';
    }

    /** Drop captured state back to the default-on pair; the tearDown of
     *  init-ing test classes so no later suite inherits a disabled footer. */
    public static function reset(): void
    {
        self::$on = true;
        self::$url = 'https://kiption.cloud';
    }

    /** Is the attribution footer on? Default true: one config line,
     *  'powered_by' => false, is the whole opt-out. */
    public static function on(): bool
    {
        return self::$on;
    }

    /** The link target (the product site; operators may point it elsewhere). */
    public static function url(): string
    {
        return self::$url;
    }
}
