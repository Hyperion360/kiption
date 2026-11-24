<?php
declare(strict_types=1);

namespace App;

/** Resolves built-in asset URLs for plain installs and immutable releases. */
final class AssetUrl
{
    private static string $prefix = '/assets/';

    public static function init(array $config): void
    {
        $digest = $config['asset_digest'] ?? '';
        if (!is_string($digest) || ($digest !== '' && preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1)) {
            throw new \InvalidArgumentException('Invalid asset digest');
        }
        self::$prefix = $digest === '' ? '/assets/' : '/assets/releases/' . $digest . '/';
    }

    public static function reset(): void
    {
        self::$prefix = '/assets/';
    }

    public static function for(string $name): string
    {
        if (preg_match('#^[a-z0-9][a-z0-9._/-]*$#D', $name) !== 1 ||
            preg_match('#(?:^|/)\.\.?(/|$)#', $name) === 1 || str_contains($name, '//')) {
            throw new \InvalidArgumentException('Invalid asset name');
        }
        return self::$prefix . $name . self::version($name);
    }

    /**
     * Plain installs serve /assets/ in place, so a CDN keeps the old file after
     * a deploy. A query string built from the file's mtime and size changes
     * whenever the file does, which makes every edge fetch the new copy
     * without a purge. Release builds already carry a digest in the path.
     */
    private static function version(string $name): string
    {
        if (self::$prefix !== '/assets/') {
            return '';
        }
        static $seen = [];
        if (!isset($seen[$name])) {
            $stat = @stat(self::assetsDir() . $name);
            $seen[$name] = $stat === false ? '' : '?v=' . self::token($stat['mtime'] . '-' . $stat['size']);
        }
        return $seen[$name];
    }

    /**
     * One token for every script app.js loads on demand (editor, paste, and the
     * rest). Those files are fetched by name from app.js, so they cannot carry
     * their own per-file version; this changes whenever any of them does.
     * Release builds already carry a digest in the path, so they get ''.
     */
    public static function scriptsVersion(): string
    {
        if (self::$prefix !== '/assets/') {
            return '';
        }
        static $token = null;
        if ($token === null) {
            $parts = [];
            foreach (glob(self::assetsDir() . '*.js') ?: [] as $file) {
                $stat = @stat($file);
                if ($stat !== false) {
                    $parts[] = basename($file) . $stat['mtime'] . '-' . $stat['size'];
                }
            }
            sort($parts);
            $token = self::token(implode('|', $parts));
        }
        return $token;
    }

    private static function assetsDir(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/';
    }

    private static function token(string $material): string
    {
        return substr(md5($material), 0, 10);
    }

    public static function icon(string $name): string
    {
        if (!in_array($name, ['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'], true)) {
            throw new \InvalidArgumentException('Unknown site icon');
        }
        return self::$prefix === '/assets/' ? '/' . $name : self::$prefix . $name;
    }
}
