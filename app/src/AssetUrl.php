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
        return self::$prefix . $name;
    }

    public static function icon(string $name): string
    {
        if (!in_array($name, ['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'], true)) {
            throw new \InvalidArgumentException('Unknown site icon');
        }
        return self::$prefix === '/assets/' ? '/' . $name : self::$prefix . $name;
    }
}
