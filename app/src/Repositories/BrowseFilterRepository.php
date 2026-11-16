<?php
declare(strict_types=1);
namespace App\Repositories;
use Kip\Database;

/** The picker, exact counts, and indexed result page share one statement. */
final class BrowseFilterRepository
{
    public const TAGS_PER_TYPE = 100;

    public function __construct(private Database $db) {}

    /** The viewer's mutes apply when the feature is enabled; zero denotes a guest. */
    public function page(BrowseQuery $q, int $viewer = 0): array
    {
        [$sql, $binds] = $this->statement($q, $viewer);
        return DiscoveryFold::partition($this->db->all($sql, $binds), BrowseQuery::PER_PAGE,
            array_merge($q->tags->include, $q->tags->exclude));
    }

    /** @return array{0:string,1:list<mixed>} Statement and binds in SQL order. */
    public function statement(BrowseQuery $q, int $viewer = 0): array
    {
        $source = 'SELECT s.id,s.slug,s.title,s.summary,s.completed,s.word_count,s.updated_at,s.created_at,
            s.author_id,s.rating_id,s.language,u.penname,u.profile_slug,r.label rating_label,
            0 tier,0 distance,0 field_rank,0.0 rank
            FROM stories s JOIN users u ON u.id=s.author_id JOIN ratings r ON r.id=s.rating_id
            WHERE s.validated=1 AND s.deleted_at IS NULL AND s.is_restricted=0 AND s.access_hash IS NULL';
        $binds = [];
        if ($viewer > 0) { $source .= MuteRepository::clause('s'); $binds[] = $viewer; }
        return DiscoveryFold::statement($source, $binds, DiscoveryFilter::fromBrowse($q), $q->sort,
            BrowseQuery::PER_PAGE, $q->offset(), false);
    }
}
