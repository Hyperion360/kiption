<?php
declare(strict_types=1);
namespace App\Repositories;
/** One statement for ordered discovery results, totals, and exact facet counts. */
final class DiscoveryFold
{
    public static function statement(string $source, array $sourceBinds, array $filters,
        string $order, int $limit, int $offset, bool $materialize, bool $listStories = true): array
    {
        $orders = [
          'relevance'=>'s.tier,s.distance,s.field_rank,s.rank,s.id',
          'updated'=>'s.updated_at DESC,s.id DESC',
          'created'=>'s.created_at DESC,s.id DESC',
          'words'=>'s.word_count DESC,s.id DESC',
          'title'=>'s.title COLLATE NOCASE,s.id',
        ];
        if (!isset($orders[$order])) throw new \InvalidArgumentException('Unknown discovery order');
        $ctes = ['source AS ' . ($materialize ? 'MATERIALIZED' : 'NOT MATERIALIZED') . ' (' . $source . ')'];
        $binds = $sourceBinds;
        // Keep ordered page reads inline; counts reuse narrow materialized rows.
        [$pageWhere, $pageBinds] = DiscoveryFilter::sql($filters);
        $ctes[] = 'page_matches AS NOT MATERIALIZED (SELECT s.* FROM source s WHERE 1=1' . $pageWhere . ')';
        array_push($binds, ...$pageBinds);
        // Facets without an active filter share the same base; bind values are part of its identity.
        $filterSets = []; $bases = [];
        foreach (['matches'=>'','category_base'=>'category','status_base'=>'status','language_base'=>'language',
            'rating_base'=>'rating','exclusion_base'=>'exclusions'] as $name=>$omit) {
            [$where,$more] = DiscoveryFilter::sql($filters,$omit);
            $key = serialize([$where, $more]);
            if (isset($filterSets[$key])) {
                $bases[$name] = $filterSets[$key];
                $ctes[] = $name . ' AS NOT MATERIALIZED (SELECT * FROM ' . $bases[$name] . ')';
            } else {
                $bases[$name] = $filterSets[$key] = $name;
                $ctes[] = $name . ' AS MATERIALIZED'
                    . ' (SELECT s.id,s.rating_id,s.completed,s.language FROM source s WHERE 1=1' . $where . ')';
                array_push($binds,...$more);
            }
        }
        $tags = $filters['tag_filter'] ?? TagFilter::fromQuery($filters);
        $selected = array_values(array_unique(array_merge($tags->include, $tags->exclude)));
        $binds[] = json_encode($selected, JSON_THROW_ON_ERROR);
        $ctes[] = 'listed_tags AS MATERIALIZED (SELECT t.* FROM tag_types g JOIN tags t ON t.id IN '
            . '(SELECT id FROM tags WHERE tag_type_id=g.id AND +canonical_id IS NULL ORDER BY name COLLATE NOCASE LIMIT ' . BrowseFilterRepository::TAGS_PER_TYPE . '))';
        $ctes[] = 'picked_tags AS (SELECT * FROM listed_tags UNION ALL SELECT t.* FROM tags t '
            . 'WHERE t.id IN (SELECT CAST(value AS INTEGER) FROM json_each(?)) '
            . 'AND NOT EXISTS(SELECT 1 FROM listed_tags l WHERE l.id=t.id))';
        // A broad, unranked source has many more attachments than displayed
        // tags. Seek the displayed canonical tags first instead of grouping
        // every story-tag attachment in the archive.
        $broadTags = !$materialize && $pageWhere === '';
        if ($broadTags) {
            $ctes[] = 'counted_tags AS MATERIALIZED (SELECT DISTINCT COALESCE(canonical_id,id) id FROM picked_tags)';
        }
        foreach (['include'=>'matches','exclude'=>'exclusion_base'] as $prefix=>$base) {
            if ($prefix === 'exclude' && $bases['matches'] === $bases['exclusion_base']) {
                $ctes[] = 'exclude_tag_counts AS (SELECT * FROM include_tag_counts)';
                continue;
            }
            $ctes[] = $broadTags
                ? $prefix.'_tag_counts AS MATERIALIZED (SELECT p.id tag_id,COUNT(DISTINCT count_rows.id) n '
                    .'FROM counted_tags p JOIN tags t ON t.id=p.id OR t.canonical_id=p.id '
                    .'CROSS JOIN story_tags st JOIN '.$base.' count_rows ON count_rows.id=st.story_id '
                    .'WHERE st.tag_id=t.id GROUP BY p.id)'
                : $prefix.'_tag_counts AS MATERIALIZED (SELECT COALESCE(t.canonical_id,t.id) tag_id,COUNT(DISTINCT count_rows.id) n '
                    .'FROM '.$base.' count_rows CROSS JOIN story_tags st JOIN tags t ON t.id=st.tag_id WHERE st.story_id=count_rows.id '
                    .'GROUP BY COALESCE(t.canonical_id,t.id))';
        }
        $ctes[] = 'category_counts AS (SELECT sc.category_id,COUNT(*) n FROM category_base category_rows '
            . 'CROSS JOIN story_categories sc WHERE sc.story_id=category_rows.id GROUP BY sc.category_id)';
        foreach (['language'=>'language','rating'=>'rating_id','status'=>'completed'] as $kind=>$column) {
            $ctes[] = $kind.'_counts AS (SELECT '.$column.',COUNT(*) n FROM '.$kind.'_base facet_rows GROUP BY '.$column.')';
        }
        $json = "json_object('id',page_rows.id,'slug',page_rows.slug,'title',page_rows.title,'summary',page_rows.summary,'completed',page_rows.completed,
            'word_count',page_rows.word_count,'updated_at',page_rows.updated_at,'penname',page_rows.penname,'profile_slug',page_rows.profile_slug,'rating_label',page_rows.rating_label,
            'tier',page_rows.tier,'distance',page_rows.distance,'field_rank',page_rows.field_rank,'rank',page_rows.rank)";
        $sql = 'WITH ' . implode(',', $ctes) . "
          SELECT 's' k,0 p,{$json} payload
            FROM (SELECT * FROM page_matches s ORDER BY {$orders[$order]} LIMIT ? OFFSET ?) page_rows
          UNION ALL SELECT 'n',0,json_object('total',COUNT(*)) FROM matches
          UNION ALL SELECT 'c',c.position,json_object('slug',c.slug,'name',c.name,'count',
            COALESCE((SELECT n FROM category_counts WHERE category_id=c.id),0)) FROM categories c
          UNION ALL SELECT 'g',g.position,json_object('id',g.id,'name',g.name) FROM tag_types g
          UNION ALL SELECT 't',t.id,json_object('id',t.id,'name',t.name,'type',t.tag_type_id,
            'listed',EXISTS(SELECT 1 FROM listed_tags z WHERE z.id=t.id),
            'count',COALESCE(ic.n,0),'excludedCount',COALESCE(ec.n,0)) FROM picked_tags t
            LEFT JOIN include_tag_counts ic ON ic.tag_id=COALESCE(t.canonical_id,t.id)
            LEFT JOIN exclude_tag_counts ec ON ec.tag_id=COALESCE(t.canonical_id,t.id)
          UNION ALL SELECT 'r',r.position,json_object('id',r.id,'label',r.label,'count',
            COALESCE((SELECT n FROM rating_counts rating_rows WHERE rating_rows.rating_id=r.id),0)) FROM ratings r
          UNION ALL SELECT 'v',v.n,json_object('value',v.n,'count',
            COALESCE((SELECT n FROM status_counts status_rows WHERE status_rows.completed=v.n),0)) FROM (SELECT 0 n UNION ALL SELECT 1) v
          UNION ALL SELECT 'l',0,json_object('value',l.language,'count',COALESCE(lc.n,0))
            FROM (SELECT DISTINCT language FROM source WHERE language<>'') l
            LEFT JOIN language_counts lc ON lc.language=l.language";
        array_push($binds, $listStories ? max(1,min(100,$limit))+1 : 0,max(0,$offset));
        return [$sql,$binds];
    }
    public static function partition(array $rows, int $limit, array $selected = []): array
    {
        $limit = max(1, min(100, $limit));
        $out=['rows'=>[],'categories'=>[],'types'=>[],'tags'=>[],'ratings'=>[],'languages'=>[],
            'selected'=>[],'counts'=>['status'=>[],'language'=>[]],'total'=>0,'hasMore'=>false];
        $stories=[];
        foreach ($rows as $row) {
            $v=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
            switch ($row['k']) {
              case 's': $stories[]=$v; break;
              case 'n': $out['total']=(int)$v['total']; break;
              case 'c': $v['position']=(int)$row['p']; $out['categories'][]=$v; break;
              case 'g': $v['position']=(int)$row['p']; $out['types'][]=$v; break;
              case 't':
                $listed=(bool)$v['listed']; unset($v['listed']);
                if ($listed) $out['tags'][]=$v;
                if (in_array((int)$v['id'],$selected,true)) $out['selected'][]=$v;
                break;
              case 'r': $v['position']=(int)$row['p']; $out['ratings'][]=$v; break;
              case 'v': $out['counts']['status'][(int)$v['value']]=(int)$v['count']; break;
              case 'l': $out['languages'][]=$v['value']; $out['counts']['language'][$v['value']]=(int)$v['count']; break;
            }
        }
        foreach (['categories','types','ratings'] as $key) {
            usort($out[$key],fn($a,$b)=>[$a['position'],$a['name'] ?? $a['label']]<=>[$b['position'],$b['name'] ?? $b['label']]);
            $out[$key]=array_map(static function($v) { unset($v['position']); return $v; },$out[$key]);
        }
        $out['hasMore']=count($stories)>$limit;
        $out['rows']=array_slice(array_values($stories),0,$limit);
        usort($out['tags'],fn($a,$b)=>[$a['type'],mb_strtolower($a['name'], 'UTF-8'),$a['id']]<=>[$b['type'],mb_strtolower($b['name'], 'UTF-8'),$b['id']]);
        sort($out['languages']);
        ksort($out['counts']['language']);
        return $out;
    }
}
