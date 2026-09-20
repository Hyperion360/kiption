<?php // app/src/Slug.php
namespace App;

final class Slug
{
    public static function make(string $title, string $fallback = 'story'): string
    {
        $s = strtolower(trim($title));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = substr($s, 0, 60);
        return $s === '' ? $fallback : $s;
    }

    /** @param callable(string): bool $exists */
    public static function unique(callable $exists, string $base): string
    {
        $slug = $base;
        for ($n = 2; $n < 52; $n++) {
            if (!$exists($slug)) return $slug;
            $slug = $base . '-' . $n;
        }
        throw new \RuntimeException('slug collision storm');
    }
}
