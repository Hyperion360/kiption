<?php // app/src/Repositories/TagFilter.php
declare(strict_types=1);
namespace App\Repositories;

/** Tag constraints for story listings. Validation lives here once; Browse
 *  Filter and Search both call sql(). A tag matches through its canonical, so
 *  a story tagged with a retired synonym still matches the canonical tag. */
final class TagFilter
{
    public const MAX = 8;
    private const NAME_LEN = 64;

    /** @param list<int> $include @param list<int> $exclude @param list<string> $names */
    private function __construct(
        public readonly array $include,
        public readonly array $exclude,
        public readonly array $names,
    ) {}

    public static function fromQuery(array $q): self
    {
        $inc = self::ids($q['tag'] ?? []);
        // Read enough excluded ids that dropping the included ones still leaves MAX.
        $exc = array_values(array_diff(self::ids($q['xtag'] ?? [], self::MAX + count($inc)), $inc));
        return new self($inc, array_slice($exc, 0, self::MAX), self::names($q['tags'] ?? ''));
    }

    public function isEmpty(): bool
    {
        return !$this->include && !$this->exclude && !$this->names;
    }

    /** " AND EXISTS(...) ..." (leading space, or '' when empty). Appends binds in SQL order. */
    public function sql(array &$binds, string $s = 's'): string
    {
        $sql = '';
        $same = fn(string $m) => " AND {$m}(SELECT 1 FROM story_tags x JOIN tags tf ON tf.id = x.tag_id"
            . " WHERE x.story_id = {$s}.id AND COALESCE(tf.canonical_id, tf.id) = (SELECT COALESCE(canonical_id, id) FROM tags WHERE id = ?))";
        foreach ($this->include as $id) {
            $sql .= $same('EXISTS');
            $binds[] = $id;
        }
        foreach ($this->exclude as $id) {
            $sql .= $same('NOT EXISTS');
            $binds[] = $id;
        }
        foreach ($this->names as $n) {
            $sql .= " AND EXISTS(SELECT 1 FROM story_tags x JOIN tags tf ON tf.id = x.tag_id"
                . " WHERE x.story_id = {$s}.id AND COALESCE(tf.canonical_id, tf.id) IN"
                . " (SELECT COALESCE(canonical_id, id) FROM tags WHERE name = ? COLLATE NOCASE))";
            $binds[] = $n;
        }
        return $sql;
    }

    /** @return list<int> */
    private static function ids(mixed $v, int $limit = self::MAX): array
    {
        $out = [];
        foreach ((array) $v as $x) {
            if (is_array($x) || !is_numeric($x)) {
                continue;
            }
            $i = (int) $x;
            if ($i > 0 && !in_array($i, $out, true)) {
                $out[] = $i;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function names(mixed $v): array
    {
        if (is_array($v)) {
            return [];
        }
        $out = [];
        foreach (explode(',', (string) $v) as $n) {
            $n = trim((string) preg_replace('/\s+/u', ' ', $n));
            $n = mb_substr($n, 0, self::NAME_LEN);
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }
}
