<?php
declare(strict_types=1);
namespace App\Search;

/** The filters in force on a search, one chip each, with a link that drops
 *  just that filter and keeps the rest. Plain links, so it works without
 *  scripts. */
final class ActiveFilters
{
    /**
     * @param array<string,mixed> $filters  as built by SearchController
     * @param array{category?: array<string,string>, rating?: array<int,string>, tag?: array<int,string>} $names
     * @return list<array{label: string, kind: string, remove: string}>
     */
    public static function chips(string $q, array $filters, string $sort, array $names): array
    {
        $base = [
            'q' => $q, 'category' => $filters['category'] ?? [], 'rating' => $filters['rating_id'] ?? null,
            'completed' => ($filters['completed'] ?? null) === null ? null : (int) $filters['completed'],
            'language' => $filters['language'] ?? null, 'tag' => $filters['tag'] ?? null, 'xtag' => $filters['xtag'] ?? null,
            'tags' => $filters['tags'] ?? '', 'xtags' => $filters['xtags'] ?? '',
            'character' => $filters['character'] ?? null, 'sort' => $sort === 'updated' ? 'updated' : null,
        ];
        $url = static function (array $drop) use ($base): string {
            $keep = array_replace($base, $drop);
            $keep = array_filter($keep, static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
            return '/search' . ($keep === [] ? '' : '?' . http_build_query($keep));
        };
        $out = [];
        foreach ($base['category'] as $slug) {
            $out[] = ['label' => $names['category'][$slug] ?? $slug, 'kind' => 'include',
                'remove' => $url(['category' => array_values(array_diff($base['category'], [$slug]))])];
        }
        if ($base['rating'] !== null) $out[] = ['label' => $names['rating'][$base['rating']] ?? (string) $base['rating'], 'kind' => 'include', 'remove' => $url(['rating' => null])];
        if ($base['completed'] !== null) $out[] = ['label' => $base['completed'] === 1 ? 'Complete' : 'In Progress', 'kind' => 'include', 'remove' => $url(['completed' => null])];
        if ($base['language'] !== null) $out[] = ['label' => (string) $base['language'], 'kind' => 'include', 'remove' => $url(['language' => null])];
        foreach (['tag' => 'include', 'xtag' => 'exclude'] as $key => $kind) {
            foreach ($base[$key] ?? [] as $id) {
                $out[] = ['label' => $names['tag'][$id] ?? ('#' . $id), 'kind' => $kind,
                    'remove' => $url([$key => array_values(array_diff($base[$key], [$id]))])];
            }
        }
        foreach (['tags' => 'include', 'xtags' => 'exclude'] as $key => $kind) {
            foreach (array_filter(explode(',', (string) $base[$key])) as $name) {
                $rest = array_values(array_diff(array_map('trim', explode(',', (string) $base[$key])), [trim($name)]));
                $out[] = ['label' => trim($name), 'kind' => $kind, 'remove' => $url([$key => implode(',', $rest)])];
            }
        }
        if ($base['character'] !== null) $out[] = ['label' => \App\Lang::t('search.filtered_character'), 'kind' => 'include', 'remove' => $url(['character' => null])];
        return $out;
    }
}
