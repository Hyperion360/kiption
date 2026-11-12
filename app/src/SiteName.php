<?php // app/src/SiteName.php
namespace App;

/**
 * The archive's display name for the header, footer, home heading and reader
 * bar (the Attribution precedent: init once at boot from the MERGED config,
 * static reads in templates, which have no config access). It must init after
 * the settings overlay so a name saved on /settings shows on the next render,
 * in web requests and in pages:build alike. Callers escape it: the value is
 * operator text, only length-checked on save.
 */
final class SiteName
{
    private const DEFAULT = 'Kiption';

    private static string $name = self::DEFAULT;

    /** Capture site_name from the boot config; blank or missing keeps the default. */
    public static function init(array $config): void
    {
        $name = $config['site_name'] ?? '';
        self::$name = is_string($name) && trim($name) !== '' ? $name : self::DEFAULT;
    }

    /** Back to the default; the tearDown of tests that init it. */
    public static function reset(): void
    {
        self::$name = self::DEFAULT;
    }

    public static function get(): string
    {
        return self::$name;
    }
}
