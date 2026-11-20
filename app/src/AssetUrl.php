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
        $stat = @stat(dirname(__DIR__, 2) . '/public/assets/' . $name);
        return $stat === false ? '' : '?v=' . substr(md5($stat['mtime'] . '-' . $stat['size']), 0, 10);
    }

    public static function icon(string $name): string
    {
        if (!in_array($name, ['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'], true)) {
            throw new \InvalidArgumentException('Unknown site icon');
        }
        return self::$prefix === '/assets/' ? '/' . $name : self::$prefix . $name;
    }
}
