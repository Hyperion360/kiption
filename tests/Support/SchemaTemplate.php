<?php // tests/Support/SchemaTemplate.php
namespace App\Tests\Support;

use Kip\Database;
use Kip\Migrations\Migrator;

/** Hands a test a real, file-backed SQLite database that is already migrated
 *  (and optionally seeded), without paying for every migration per test.
 *
 *  The schema is built once per fingerprint (the migration files plus app/src,
 *  because the seeder runs app code) by the real Migrator and the real Seeder,
 *  then stored as a plain database file. Each test copies that file: still a
 *  real database, a real schema, real queries, no fake. A test that asserts on
 *  migration behavior itself keeps calling the Migrator directly.
 *
 *  Safe under parallel workers: the template name carries the fingerprint, it
 *  is built beside its final name and renamed into place, and workers that race
 *  to build it write identical bytes. */
final class SchemaTemplate
{
    /** @var array<string,string> variant => template file, per process */
    private static array $ready = [];
    private static ?string $fingerprint = null;

    /** A fresh path (ending .sqlite) holding a copy of the migrated schema.
     *  Mirrors `tempnam(...) . '.sqlite'`: the tempnam stub exists too, so the
     *  teardown idiom that unlinks both keeps working. */
    public static function path(string $prefix): string
    {
        return self::copyTo($prefix, 'migrated');
    }

    /** Same, with the demo seeder's rows already applied (Seeder::run). */
    public static function seededPath(string $prefix): string
    {
        return self::copyTo($prefix, 'seeded');
    }

    private static function copyTo(string $prefix, string $variant): string
    {
        $stub = tempnam(sys_get_temp_dir(), $prefix);
        $path = $stub . '.sqlite';
        if (!copy(self::template($variant), $path)) {
            throw new \RuntimeException("cannot copy the {$variant} schema template to {$path}");
        }
        return $path;
    }

    private static function template(string $variant): string
    {
        if (isset(self::$ready[$variant])) return self::$ready[$variant];
        $file = sys_get_temp_dir() . '/kiption-schema-' . self::fingerprint() . '-' . $variant . '.sqlite';
        if (!is_file($file)) self::build($file, $variant);
        return self::$ready[$variant] = $file;
    }

    private static function build(string $file, string $variant): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $db = new Database('sqlite:' . $tmp);
        (new Migrator($db, AppLayout::migrations()))->migrate();
        if ($variant === 'seeded') \App\Seeder::run($db);
        $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
        $db->query('PRAGMA journal_mode = DELETE'); // one self-contained file, no -wal/-shm beside it
        unset($db);
        @unlink($tmp . '-wal');
        @unlink($tmp . '-shm');
        if (!@rename($tmp, $file)) @unlink($tmp); // a racing worker won; its bytes are equivalent
    }

    /** Hash of everything the template depends on. */
    private static function fingerprint(): string
    {
        if (self::$fingerprint !== null) return self::$fingerprint;
        $root = dirname(__DIR__, 2);
        $ctx = hash_init('sha1');
        $dirs = array_merge(AppLayout::migrations(), [$root . '/app/src']);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            $files = [];
            foreach ($it as $f) if ($f->isFile()) $files[] = $f->getPathname();
            sort($files);
            foreach ($files as $f) {
                hash_update($ctx, substr($f, strlen($root)) . "\0");
                hash_update($ctx, (string) file_get_contents($f) . "\0");
            }
        }
        hash_update($ctx, PHP_VERSION);
        return self::$fingerprint = substr(hash_final($ctx), 0, 16);
    }
}
