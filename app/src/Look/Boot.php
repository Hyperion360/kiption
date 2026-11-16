<?php // app/src/Look/Boot.php
declare(strict_types=1);
namespace App\Look;

/** What the layout reads. No queries: the saved row rode the boot statement. */
final class Boot
{
    private static string $rev = '';

    /** Folder of the compiled file under the private data directory. */
    public static function dir(array $config): string
    {
        return (string) ($config['site_look_dir'] ?? rtrim((string) ($config['data_dir'] ?? dirname(__DIR__, 3) . '/app'), '/') . '/site-look');
    }

    /** Capture the revision once per boot, after the settings overlay. */
    public static function init(): void
    {
        $v = Store::get(); // Settings::get already treats an unmigrated database as no row
        self::$rev = $v === null ? '' : Compiler::revision($v);
    }

    public static function reset(): void { self::$rev = ''; }

    /** '' when the owner has not saved a look (the layout then adds nothing). */
    public static function href(): string { return self::$rev === '' ? '' : '/look/css?v=' . self::$rev; }
}
