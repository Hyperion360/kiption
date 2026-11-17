<?php
declare(strict_types=1);

namespace App\Setup;

/** Coordinates schema changes with an external directory-exchange operator. */
final class MigrationLock
{
    public static function run(?string $path, callable $work): mixed
    {
        if ($path === null || $path === '') return $work();
        if ($path[0] !== '/' || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Invalid migration lock path');
        }
        $part = '';
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '') continue;
            $part .= '/' . $segment;
            if (is_link($part)) throw new \RuntimeException('Invalid migration lock path');
        }
        $before = @lstat($path);
        if ($before === false || !is_file($path) || ($before['mode'] & 0170000) !== 0100000 ||
            ($before['mode'] & 077) !== 0) {
            throw new \RuntimeException('Invalid migration lock protocol');
        }
        $handle = @fopen($path, 'r+');
        if ($handle === false) throw new \RuntimeException('Cannot open migration lock');
        try {
            $after = fstat($handle);
            if ($after === false || $after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino']) {
                throw new \RuntimeException('Migration lock changed during open');
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Migration lock is held');
            try {
                rewind($handle);
                if (stream_get_contents($handle) !== 'v1') {
                    throw new \RuntimeException('Invalid migration lock protocol');
                }
                return $work();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
