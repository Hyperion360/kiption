<?php
declare(strict_types=1);
namespace App\Search;

/** The filters in force on a search, one chip each, with a link that drops
 *  just that filter and keeps the rest. Plain links, so it works without
 *  scripts. */
final class ActiveFilters
{
    /** @return array<string,mixed> every query parameter a search carries */
    private static function base(string $q, array $filters, string $sort): array
    {
        return [
            'q' => $q, 'category' => $filters['category'] ?? [], 'rating' => $filters['rating_id'] ?? null,
            'completed' => ($filters['completed'] ?? null) === null ? null : (int) $filters['completed'],
            'language' => $filters['language'] ?? null, 'tag' => $filters['tag'] ?? null, 'xtag' => $filters['xtag'] ?? null,
            'tags' => $filters['tags'] ?? '', 'xtags' => $filters['xtags'] ?? '',
            'character' => $filters['character'] ?? null, 'sort' => $sort === 'updated' ? 'updated' : null,
        ];
    }

    /** The search with the typed words cleared and every filter kept (the field's clear button). */
    public static function withoutQuery(array $filters, string $sort): string
    {
        $keep = array_filter(self::base('', $filters, $sort), static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
        return '/search' . ($keep === [] ? '' : '?' . http_build_query($keep));
    }

    /**
     * @param array<string,mixed> $filters  as built by SearchController
     * @param array{category?: array<string,string>, rating?: array<int,string>, status?: array<int,string>, tag?: array<int,string>} $names
     * @return list<array{label: string, kind: string, type: string, remove: string}>
     */
    public static function chips(string $q, array $filters, string $sort, array $names): array
    {
        $base = self::base($q, $filters, $sort);
        $url = static function (array $drop) use ($base): string {
            $keep = array_replace($base, $drop);
            $keep = array_filter($keep, static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
            return '/search' . ($keep === [] ? '' : '?' . http_build_query($keep));
        };
        $out = [];
        foreach ($base['category'] as $slug) {
            $out[] = ['label' => $names['category'][$slug] ?? $slug, 'kind' => 'include', 'type' => 'category',
                'remove' => $url(['category' => array_values(array_diff($base['category'], [$slug]))])];
        }
        if ($base['rating'] !== null) $out[] = ['label' => $names['rating'][$base['rating']] ?? (string) $base['rating'], 'kind' => 'include', 'type' => 'rating', 'remove' => $url(['rating' => null])];
        if ($base['completed'] !== null) $out[] = ['label' => $names['status'][$base['completed']] ?? ($base['completed'] === 1 ? 'Complete' : 'In Progress'), 'kind' => 'include', 'type' => 'status', 'remove' => $url(['completed' => null])];
        if ($base['language'] !== null) $out[] = ['label' => (string) $base['language'], 'kind' => 'include', 'type' => 'language', 'remove' => $url(['language' => null])];
        foreach (['tag' => 'include', 'xtag' => 'exclude'] as $key => $kind) {
            foreach ($base[$key] ?? [] as $id) {
                $out[] = ['label' => $names['tag'][$id] ?? ('#' . $id), 'kind' => $kind, 'type' => 'tag',
                    'remove' => $url([$key => array_values(array_diff($base[$key], [$id]))])];
            }
        }
        foreach (['tags' => 'include', 'xtags' => 'exclude'] as $key => $kind) {
            foreach (array_filter(explode(',', (string) $base[$key])) as $name) {
                $rest = array_values(array_diff(array_map('trim', explode(',', (string) $base[$key])), [trim($name)]));
                $out[] = ['label' => trim($name), 'kind' => $kind, 'type' => 'tag', 'remove' => $url([$key => implode(',', $rest)])];
            }
        }
        if ($base['character'] !== null) $out[] = ['label' => \App\Lang::t('search.filtered_character'), 'kind' => 'include', 'type' => 'character', 'remove' => $url(['character' => null])];
        return $out;
    }

    /** One filter set per chip, in chip order, each with only that filter dropped
     *  (and the pre-built TagFilter removed so tag predicates rebuild from the
     *  remaining ids and names). Ends with the set that drops every filter; empty when no filter is active. */
    public static function variants(array $filters): array
    {
        $plain = $filters;
        unset($plain['tag_filter']);
        $out = [];
        foreach (array_values($filters['category'] ?? []) as $slug) {
            $out[] = array_replace($plain, ['category' => array_values(array_diff($filters['category'], [$slug]))]);
        }
        if (($filters['rating_id'] ?? null) !== null) $out[] = array_replace($plain, ['rating_id' => null]);
        if (($filters['completed'] ?? null) !== null) $out[] = array_replace($plain, ['completed' => null]);
        if (($filters['language'] ?? null) !== null) $out[] = array_replace($plain, ['language' => null]);
        foreach (['tag', 'xtag'] as $key) {
            foreach ($filters[$key] ?? [] as $id) {
                $out[] = array_replace($plain, [$key => array_values(array_diff($filters[$key], [$id]))]);
            }
        }
        foreach (['tags', 'xtags'] as $key) {
            foreach (array_filter(explode(',', (string) ($filters[$key] ?? ''))) as $name) {
                $rest = array_values(array_diff(array_map('trim', explode(',', (string) $filters[$key])), [trim($name)]));
                $out[] = array_replace($plain, [$key => implode(',', $rest)]);
            }
        }
        if (($filters['character'] ?? null) !== null) $out[] = array_replace($plain, ['character' => null]);
        if ($out === []) return [];
        $out[] = array_replace($plain, ['category' => [], 'rating_id' => null, 'completed' => null, 'language' => null,
            'tag' => null, 'xtag' => null, 'tags' => '', 'xtags' => '', 'character' => null]);
        return $out;
    }

    /** The "no stories match" sentence's filter list, e.g. "complete, rated Explicit and tagged slow burn".
     *  Labels are HTML-escaped here, so the result is safe to print as is. */
    public static function phrase(array $chips): string
    {
        $parts = array_map(static function (array $c): string {
            $label = htmlspecialchars($c['type'] === 'status' ? mb_strtolower($c['label'], 'UTF-8') : $c['label'], ENT_QUOTES, 'UTF-8');
            return \App\Lang::t('search.phrase_' . $c['type'] . ($c['kind'] === 'exclude' ? '_out' : ''), ['label' => $label]);
        }, $chips);
        $last = array_pop($parts);
        return $parts === [] ? (string) $last : implode(', ', $parts) . \App\Lang::t('search.phrase_and') . $last;
    }
}
