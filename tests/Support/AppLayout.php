<?php // tests/Support/AppLayout.php
namespace App\Tests\Support;

/** One source of truth for where the app's migrations live. During the
 *  feature-folder reorg and after it, tests migrate the app directory plus
 *  every feature directory as one ledger, exactly like bin/kip. */
final class AppLayout
{
    /** @return list<string> */
    public static function migrations(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $dirs = [$root . '/migrations'];
        $features = $root . '/Features';
        if (!is_dir($features)) return $dirs; // no scandir warning before the first move
        foreach (scandir($features) ?: [] as $entry) {
            if ($entry[0] === '.') continue;
            $m = $features . '/' . $entry . '/migrations';
            if (is_dir($m)) $dirs[] = $m;
        }
        return $dirs;
    }
}
