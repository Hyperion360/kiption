<?php
declare(strict_types=1);
namespace App\Repositories;

/** Shared story predicates for Search and Browse, with optional dimension omission. */
final class DiscoveryFilter
{
    /** @return array{0:string,1:list<mixed>} Predicates and binds in SQL order. */
    public static function sql(array $f, string $omit = ''): array
    {
        $sql = '';
        $binds = [];
        if ($omit !== 'category') $sql .= CategoryFilter::sql(CategoryFilter::slugs($f['category'] ?? []), $binds);
        if ($omit !== 'rating' && !empty($f['rating_id'])) {
            $sql .= ' AND s.rating_id=CAST(? AS INTEGER)';
            $binds[] = (int) $f['rating_id'];
        }
        if ($omit !== 'status' && isset($f['completed'])) {
            $sql .= ' AND s.completed=CAST(? AS INTEGER)';
            $binds[] = $f['completed'] ? 1 : 0;
        }
        if ($omit !== 'language' && !empty($f['language'])) {
            $sql .= ' AND +s.language=?';
            $binds[] = $f['language'];
        }
        // Unary plus preserves Browse's sort index; CAST restores numeric comparison.
        if (isset($f['wmin'])) { $sql .= ' AND +s.word_count>=CAST(? AS INTEGER)'; $binds[] = $f['wmin']; }
        if (isset($f['wmax'])) { $sql .= ' AND +s.word_count<=CAST(? AS INTEGER)'; $binds[] = $f['wmax']; }
        $tags = ['tag' => $f['tag'] ?? [], 'tags' => $f['tags'] ?? '',
            'xtag' => $omit === 'exclusions' ? [] : ($f['xtag'] ?? []),
            'xtags' => $omit === 'exclusions' ? '' : ($f['xtags'] ?? '')];
        $sql .= TagFilter::fromQuery($tags)->sql($binds, 's', true);
        if (!empty($f['character'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM story_characters ch WHERE ch.story_id=s.id AND ch.character_id=CAST(? AS INTEGER))';
            $binds[] = (int) $f['character'];
        }
        return [$sql, $binds];
    }

    public static function fromBrowse(BrowseQuery $q): array
    {
        return ['category' => $q->categories, 'completed' => $q->status === 'any' ? null : $q->status === 'complete',
            'language' => $q->language, 'wmin' => $q->wmin, 'wmax' => $q->wmax,
            'tag' => $q->tags->include, 'xtag' => $q->tags->exclude,
            'tags' => implode(',', $q->tags->names), 'xtags' => implode(',', $q->tags->excludeNames)];
    }
}
