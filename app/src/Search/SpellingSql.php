<?php
declare(strict_types=1);
namespace App\Search;

final class SpellingSql
{
    /** Excluding exact FTS hits requires the enclosing statement to define fts_matches.
     *  @return array{string, list<string>} */
    public static function ctes(array $probe, bool $excludeFtsMatches = false): array
    {
        if ($probe['count'] === 0) return ['', []];
        $last = $probe['count'] - 1;
        // Exact stories already win; discard all their postings before ranking the remaining stories.
        $exclude = $excludeFtsMatches ? ' WHERE p.story_id NOT IN (SELECT story_id FROM fts_matches WHERE story_id IS NOT NULL)' : '';
        $prefix = mb_strlen($probe['tokens'][$last], 'UTF-8') >= 2 ? [[$last, $probe['tokens'][$last]]] : [];
        $sql = "spell_direct(i,w,c) AS (
          SELECT json_extract(value,'$[0]'),json_extract(value,'$[1]'),json_extract(value,'$[2]') FROM json_each(?)
        ), spell_sub(i,k,p) AS (
          SELECT json_extract(value,'$[0]'),json_extract(value,'$[1]'),json_extract(value,'$[2]') FROM json_each(?)
        ), spell_insert(i,k) AS (
          SELECT json_extract(value,'$[0]'),json_extract(value,'$[1]') FROM json_each(?)
        ), spell_prefix(i,k) AS (
          SELECT json_extract(value,'$[0]'),json_extract(value,'$[1]') FROM json_each(?)
        ), spell_words(i,word,cost) AS (
          SELECT p.i,w.word,p.c FROM spell_direct p CROSS JOIN search_words w WHERE w.word=p.w
          UNION ALL SELECT p.i,d.word,1 FROM spell_sub p CROSS JOIN search_deletes d
            WHERE d.key=p.k AND d.position=CAST(p.p AS INTEGER)
          UNION ALL SELECT p.i,d.word,1 FROM spell_insert p CROSS JOIN search_deletes d WHERE d.key=p.k
          UNION ALL SELECT p.i,w.word,0 FROM spell_prefix p CROSS JOIN search_words w
            WHERE w.word>=p.k AND w.word<p.k||char(1114111)
        ), spell_best AS (
          SELECT p.story_id,w.i,MIN(w.cost*2+CASE p.field WHEN 'title' THEN 0 ELSE 1 END) score
          FROM spell_words w JOIN search_word_posts p ON p.word=w.word{$exclude} GROUP BY p.story_id,w.i
        ), spell_hits AS (
          SELECT story_id,i,CAST(score/2 AS INTEGER) cost,score%2 field_rank FROM spell_best
        ), spell_matches AS (
          SELECT story_id,SUM(cost) distance,SUM(field_rank) field_rank
          FROM spell_hits GROUP BY story_id HAVING COUNT(*)=" . (int) $probe['count'] . "
        )";
        $encode = static fn (array $v): string => json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return [$sql, array_map($encode, [$probe['direct'], $probe['substitutions'], $probe['insertions'], $prefix])];
    }
}
