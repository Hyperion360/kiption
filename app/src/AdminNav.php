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

    /** What is waiting, by screen address, when the page already holds the
     *  numbers (the home and the queue read them in their one statement).
     *  Other screens leave this empty and show no badges: the shell never
     *  queries. @var array<string,int> */
    private static array $waiting = [];

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
        self::$waiting = [];
    }

    /** Hand the shell the counts the page already has, keyed by screen address
     *  (for example '/queue' => 8). Zero and missing both mean no badge. */
    public static function waiting(array $counts): void
    {
        self::$waiting = array_map('intval', $counts);
    }

    /** The address of the screen $path belongs to: the menu itself, a listed
     *  screen, or the list a sub-screen sits under. Null for every other page. */
    public static function current(string $path): ?string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/manage') return '/manage';
        if ($path === '/admin') return self::$database ? '/admin' : null; // the raw database editor sits in Advanced, not ManageController::LINKS
        if (in_array($path, array_column(ManageController::LINKS, 1), true)) return $path;
        $trail = ManageController::trail($path);
        return $trail === [] ? null : (string) end($trail)['url'];
    }

    /** Everything the layout prints around an owner screen, or null when
     *  $path is not one.
     *  Only the group holding the current screen is open (the home opens the
     *  first); a folded group carries the sum of what waits inside it.
     *  @return array{current:string,trail:list<array{url:string,label:string}>,groups:list<array{label:string,open:bool,badge:int,links:list<array{url:string,label:string,current:bool,count:int}>}>,tabs:list<array{url:string,label:string,icon:string,current:bool,count:int}>}|null */
    public static function shell(string $path): ?array
    {
        $current = self::current($path);
        if ($current === null) return null;
        $byGroup = [];
        foreach (ManageController::LINKS as $link) {
            if (isset($link[3]) && !Features::on($link[3])) continue;
            $byGroup[$link[0]][] = ['url' => $link[1], 'label' => $link[2], 'current' => $link[1] === $current, 'count' => self::$waiting[$link[1]] ?? 0];
        }
        $groups = [];
        foreach (ManageController::GROUPS as $id => $label) {
            if (!empty($byGroup[$id])) $groups[] = ['label' => $label, 'links' => $byGroup[$id]];
        }
        if (self::$database) {
            $groups[] = ['label' => 'manage.group_advanced', 'links' => [['url' => '/admin', 'label' => 'manage.database', 'current' => '/admin' === $current, 'count' => 0]]];
        }
        $opened = false;
        foreach ($groups as $i => $g) {
            $groups[$i]['open'] = false;
            $groups[$i]['badge'] = array_sum(array_column($g['links'], 'count'));
            if (!$opened && in_array(true, array_column($g['links'], 'current'), true)) { $groups[$i]['open'] = $opened = true; }
        }
        if (!$opened && $groups !== []) $groups[0]['open'] = true; // the home, or a screen outside every group
        $tabs = [];
        foreach (self::TABS as [$url, $label, $icon]) {
            $tabs[] = ['url' => $url, 'label' => $label, 'icon' => $icon, 'current' => $url === $current, 'count' => self::$waiting[$url] ?? 0];
        }
        return ['current' => $current, 'trail' => ManageController::trail($path), 'groups' => $groups, 'tabs' => $tabs];
    }
}
