<?php // app/src/LookAlikes.php
declare(strict_types=1);

namespace App;

/** Names that are probably the same thing typed two ways: they match once
 *  case, spaces and punctuation are set aside ("Slow burn" and "slowburn"). */
final class LookAlikes
{
    /** @param list<string> $names
     *  @return list<list<string>> each group holds two or more names that look alike */
    public static function groups(array $names): array
    {
        $seen = [];
        foreach ($names as $name) {
            $key = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name)) ?? '';
            if ($key !== '' && !in_array($name, $seen[$key] ?? [], true)) $seen[$key][] = $name;
        }
        return array_values(array_filter($seen, static fn (array $g): bool => count($g) > 1));
    }
}
