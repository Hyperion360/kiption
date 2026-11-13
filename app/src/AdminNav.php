<?php // app/src/AdminNav.php
namespace App;

use App\Features\Manage\ManageController;

/** The admin navigation every owner screen carries: the grouped list of
 *  screens with the current one marked, the way back above the page, and
 *  the short tab bar phones get. Built from ManageController::LINKS, so a
 *  new screen is still one line there. No database reads: the list costs an
 *  admin page nothing against its query budget. */
final class AdminNav
{
    /** The four places a phone keeps one tap away; the fifth tab opens the full list. */
    public const TABS = [
        ['/manage', 'manage.tab_home', 'home'],
        ['/queue', 'manage.tab_queue', 'queue'],
        ['/adminmembers', 'manage.tab_members', 'members'],
        ['/settings', 'manage.tab_settings', 'settings'],
    ];

    /** Is the raw database editor on (config admin.enabled)? */
    private static bool $database = true;

    /** Capture the one config switch the list needs; layouts have no config access. */
    public static function init(array $config): void
    {
        self::$database = (bool) (((array) ($config['admin'] ?? []))['enabled'] ?? true);
    }

    public static function reset(): void
    {
        self::$database = true;
    }

    /** The address of the screen $path belongs to: the menu itself, a listed
     *  screen, or the list a sub-screen sits under. Null for every other page. */
    public static function current(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/manage') return '/manage';
        if (in_array($path, array_column(ManageController::LINKS, 1), true)) return $path;
        $trail = ManageController::trail($path);
        return $trail === [] ? null : (string) end($trail)['url'];
    }

    /** Everything the layout prints around an owner screen, or null when
     *  $path is not one.
     *  @return array{current:string,trail:list<array{url:string,label:string}>,groups:list<array{label:string,links:list<array{url:string,label:string,current:bool}>}>,tabs:list<array{url:string,label:string,icon:string,current:bool}>}|null */
    public static function shell(string $path): ?array
    {
        $current = self::current($path);
        if ($current === null) return null;
        $byGroup = [];
        foreach (ManageController::LINKS as $link) {
            if (isset($link[3]) && !Features::on($link[3])) continue;
            $byGroup[$link[0]][] = ['url' => $link[1], 'label' => $link[2], 'current' => $link[1] === $current];
        }
        $groups = [];
        foreach (ManageController::GROUPS as $id => $label) {
            if (!empty($byGroup[$id])) $groups[] = ['label' => $label, 'links' => $byGroup[$id]];
        }
        if (self::$database) {
            $groups[] = ['label' => 'manage.group_advanced', 'links' => [['url' => '/admin', 'label' => 'manage.database', 'current' => false]]];
        }
        $tabs = [];
        foreach (self::TABS as [$url, $label, $icon]) {
            $tabs[] = ['url' => $url, 'label' => $label, 'icon' => $icon, 'current' => $url === $current];
        }
        return ['current' => $current, 'trail' => ManageController::trail($path), 'groups' => $groups, 'tabs' => $tabs];
    }
}
