<?php // app/src/Look/Store.php
declare(strict_types=1);
namespace App\Look;

/** The saved look: one JSON row (site_look) in the settings table. The row
 *  rides the one boot statement, so reading it costs no query. A row that got
 *  in some other way is re-validated here and never reaches the compiler. */
final class Store
{
    public const KEY = 'site_look';

    /** @return array<string,string>|null clean values, or null when nothing valid is saved */
    public static function get(): ?array
    {
        $raw = \App\Settings::get(self::KEY);
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        $r = Rules::check($data);
        return $r['errors'] === [] ? $r['values'] : null;
    }

    public static function put(array $values): void
    {
        \App\Features::putSetting(self::KEY, (string) json_encode($values, JSON_UNESCAPED_SLASHES));
    }

    public static function clear(): void
    {
        \App\Features::deleteSetting(self::KEY);
    }
}
