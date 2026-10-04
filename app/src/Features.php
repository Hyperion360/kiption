<?php // app/src/Features.php
namespace App;

/**
 * The feature-flag resolver (the Lang precedent: init once at bootstrap,
 * static seam everywhere else).
 *
 * Resolution order, lowest to highest: the INVENTORY const (all true), the
 * config-shipped defaults (config['features'], the deploy default), then
 * feature_flags DB rows (the runtime surface; one row per overridden flag).
 * Unknown keys fail CLOSED (on() false, toggle InvalidArgumentException);
 * an UNINITIALIZED class fails OPEN (finding 1, load-bearing): on() answers
 * from the inventory const alone, all true, no DB handle. That keeps every
 * suite that never inits (and a production bootstrap that somehow skips it)
 * behaving exactly as the pre-flag archive.
 */
final class Features
{
    /** The flag inventory (the contract): every discretionary surface, default on.
     *  The never-list (reading, auth, account, moderation, profiles, series,
     *  engagement, the beacon, maintenance, /features itself, the admin panel)
     *  is not representable here by design. */
    public const INVENTORY = [
        'news' => true, 'comments' => true, 'contact' => true, 'stats' => true,
        'lists' => true, 'search' => true, 'toplists' => true, 'exports' => true,
        'feeds' => true, 'directory' => true, 'digest' => true, 'analytics' => true,
        'challenges' => true, 'releases' => true, 'roundrobin' => true,
        'pms' => true, 'mute' => true, 'wrangling' => true,
        'peruserlang' => true, 'perusertheme' => true,
    ];

    private static ?\Kip\Database $db = null;

    /** @var array<string, bool> config-shipped defaults captured at init */
    private static array $defaults = [];

    /** @var array<string, bool>|null the resolved map, null = resolve lazily */
    private static ?array $memo = null;

    /** @var array<string, string> operator settings from the SAME resolution
     *  pass (empty until resolve() runs with a settings table present). */
    private static array $settings = [];

    /** Capture the DB handle and deploy defaults; re-init re-reads everything. */
    public static function init(\Kip\Database $db, array $defaults = []): void
    {
        self::$db = $db;
        self::$defaults = $defaults;
        self::$memo = null;
        self::$settings = [];
    }

    /** Drop all state (memo, DB handle, defaults); the tearDown of every
     *  init-ing test class, so no later suite inherits a stale memo. */
    public static function reset(): void
    {
        self::$db = null;
        self::$defaults = [];
        self::$memo = null;
        self::$settings = [];
    }

    /** @return array<string, string> the settings rows from the last (or next)
     *  resolution: ONE statement reads flags and settings together, so the
     *  query-budget contract keeps the boot round trips exactly where they
     *  were before settings existed. */
    public static function settings(): array
    {
        self::resolve();
        return self::$settings;
    }

    /** Write one operator setting: INSERT OR REPLACE a row, then drop BOTH
     *  memos so the next read re-resolves (the toggle() discipline). The key
     *  whitelist lives in Settings::put (the facade the board goes through);
     *  this is the raw write seam, mirroring toggle()'s trust boundary. */
    public static function putSetting(string $key, string $value): void
    {
        if (self::$db === null) {
            throw new \LogicException('Features::putSetting before init (no DB handle)');
        }
        self::$db->query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
        self::$memo = null;
        self::$settings = [];
    }

    /** Is the flag on? Unknown keys are OFF; uninit reads the inventory alone. */
    public static function on(string $key): bool
    {
        return self::resolve()[$key] ?? false;
    }

    /** The choke-point guard: null when the flag is on (the caller proceeds),
     *  the byte-identical 'Page not found' 404 when off (finding 10: exactly
     *  the router's own 404, no gratuitous oracle). One static definition so
     *  every gated action is `if (($r = Features::guard('news')) !== null)
     *  return $r;` before ANY repository call. */
    public static function guard(string $key): ?\Kip\Http\Response
    {
        return self::on($key) ? null : new \Kip\Http\Response('Page not found', 404);
    }

    /** @return array<string, array{on: bool, desc: string}> every inventory key
     *  with its state and its features.{key}.desc lang key (the toggle board). */
    public static function all(): array
    {
        $out = [];
        foreach (self::resolve() as $key => $on) {
            $out[$key] = ['on' => $on, 'desc' => 'features.' . $key . '.desc'];
        }
        return $out;
    }

    /** Flip a flag: INSERT OR REPLACE one row, then drop the memo so the next
     *  on() re-reads. Unknown keys throw (the controller maps that to a 422;
     *  no row may ever land outside the inventory). */
    public static function toggle(string $key, bool $on): void
    {
        if (!\array_key_exists($key, self::INVENTORY)) {
            throw new \InvalidArgumentException("Unknown feature flag: {$key}");
        }
        if (self::$db === null) {
            throw new \LogicException('Features::toggle before init (no DB handle)');
        }
        self::$db->query('INSERT OR REPLACE INTO feature_flags (key, enabled) VALUES (?, ?)', [$key, $on ? 1 : 0]);
        self::$memo = null;
    }

    /** @return array<string, bool> inventory <- config defaults <- DB rows
     *  Flags and settings resolve in ONE statement (UNION ALL fold): the boot
     *  query count is exactly what it was before settings existed. GUARDED so
     *  an unmigrated database (the git-pull window before `migrate` runs)
     *  degrades to flags-only instead of fataling every page and CLI arm; a
     *  database with NEITHER table throws from the fallback, and the callers
     *  that must survive that (Settings::apply, the CLI seams) catch it. */
    private static function resolve(): array
    {
        if (self::$memo !== null) return self::$memo;
        $map = self::INVENTORY;
        foreach (self::$defaults as $key => $on) {
            if (\array_key_exists($key, $map)) $map[$key] = (bool) $on;
        }
        self::$settings = [];
        if (self::$db !== null) {
            try {
                $rows = self::$db->all("SELECT key, enabled AS v, 'flag' AS src FROM feature_flags
                                        UNION ALL SELECT key, value, 'setting' FROM settings");
            } catch (\Throwable) {
                $rows = self::$db->all('SELECT key, enabled AS v, \'flag\' AS src FROM feature_flags'); // pre-settings DB
            }
            foreach ($rows as $row) {
                if ($row['src'] === 'flag' && \array_key_exists($row['key'], $map)) $map[$row['key']] = (bool) $row['v'];
                if ($row['src'] === 'setting') self::$settings[$row['key']] = (string) $row['v'];
            }
        }
        return self::$memo = $map;
    }
}
