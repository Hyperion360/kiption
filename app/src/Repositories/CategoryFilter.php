<?php
declare(strict_types=1);
namespace App\Repositories;

/** The bounded category selection shared by search and browse. */
final class CategoryFilter
{
    /** @return list<string> */
    public static function slugs(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [$raw] as $slug) {
            if (!is_string($slug) || preg_match('/^[a-z0-9-]{1,100}$/D', $slug) !== 1) continue;
            if (!in_array($slug, $out, true)) $out[] = $slug;
            if (count($out) === 8) break;
        }
        return $out;
    }

    /** @param list<string> $slugs Validated category slugs. */
    public static function sql(array $slugs, array &$binds): string
    {
        if ($slugs === []) return '';
        array_push($binds, ...$slugs);
        return ' AND EXISTS (SELECT 1 FROM story_categories sc JOIN categories cc ON cc.id = sc.category_id'
            . ' WHERE sc.story_id = s.id AND cc.slug IN (' . implode(', ', array_fill(0, count($slugs), '?')) . '))';
    }
}
