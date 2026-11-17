<?php
declare(strict_types=1);
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Repositories\{AuthoringRepository, SearchRepository};
use App\Tests\Support\{SearchArchive, TaxonomyCase};

/** Search sees committed editor and taxonomy changes without recreating the repository. */
#[Small]
final class SearchLifecycleTest extends TaxonomyCase
{
    private SearchArchive $archive;
    private SearchRepository $search;
    private AuthoringRepository $authoring;
    private int $rating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rating = (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        $this->archive = new SearchArchive($this->db, $this->authorId, $this->rating);
        $this->archive->clear();
        $this->search = new SearchRepository($this->db);
        $this->authoring = new AuthoringRepository($this->db);
    }

    private function ids(string $query): array
    {
        $result = $this->search->searchWithTaxonomies($query, [], 100, 0, 0);
        $ids = array_map('intval', array_column($result['rows'], 'id'));
        $this->assertSame(count($ids), $result['total'], 'No duplicate story IDs in the exact count.');
        return $ids;
    }

    private function create(string $title, array $tags = []): array
    {
        return $this->authoring->createStory($this->authorId, $title, 'Synthetic story.', '', $this->rating,
            [], true, false, 'en', tagIds: $tags);
    }

    private function update(string $slug, string $title, array $tags = []): string
    {
        return $this->authoring->updateStory($slug, $this->authorId, $title, 'Synthetic story.', '',
            $this->rating, [], false, false, 'en', tagIds: $tags)[0];
    }

    public function test_authoring_create_rename_attach_detach_and_delete_reach_search(): void
    {
        [$id, $slug] = $this->create('Wonderland at Midnight');
        foreach (['wonderlnd', 'wonderlaand', 'wonderlend', 'wondreland'] as $query) $this->assertSame([$id], $this->ids($query));
        $slug = $this->update($slug, 'Cartographer at Midnight');
        $this->assertSame([], $this->ids('wonderlend'));
        $this->assertSame([$id], $this->ids('cartogropher'));
        $tag = $this->archive->tag('Botanical');
        $this->update($slug, 'Cartographer at Midnight', [$tag]);
        $this->assertSame([$id], $this->ids('botanial'));
        $this->update($slug, 'Cartographer at Midnight');
        $this->assertSame([], $this->ids('botanial'));
        $this->authoring->deleteStory($slug, $this->authorId);
        $this->assertSame([], $this->ids('cartogropher'));
    }

    public function test_taxonomy_rename_merge_unmerge_and_delete_update_the_existing_repository(): void
    {
        $canonical = $this->archive->tag('Botanical');
        [$story] = $this->create('A Quiet Journey', [$canonical]);
        $type = (int) $this->db->one('SELECT tag_type_id FROM tags WHERE id=?', [$canonical])['tag_type_id'];
        $admin = $this->admin();
        $this->assertSame(302, $admin->postWithToken('/tags/addtag', ['tag_type_id' => $type, 'name' => 'Forestcanary'])->status);
        $alias = (int) $this->db->one('SELECT id FROM tags WHERE name=?', ['Forestcanary'])['id'];
        $this->assertSame([], $this->ids('forestcanry'));
        $this->assertSame(302, $admin->postWithToken('/wrangling/merge', ['synonym_id' => $alias, 'canonical_id' => $canonical])->status);
        $this->assertSame([$story], $this->ids('forestcanry'));
        $this->assertSame(302, $admin->postWithToken('/tags/update/' . $alias, ['name' => 'Meadowcanary'])->status);
        $this->assertSame([], $this->ids('forestcanry'));
        $this->assertSame([$story], $this->ids('meadowcanry'));
        $this->assertSame(302, $admin->postWithToken('/wrangling/unmerge/' . $alias)->status);
        $this->assertSame([], $this->ids('meadowcanry'));
        $this->assertSame(302, $admin->postWithToken('/tags/update/' . $canonical, ['name' => 'Woodland'])->status);
        $this->assertSame([], $this->ids('botanial'));
        $this->assertSame([$story], $this->ids('woodlnd'));
        $this->assertSame(302, $admin->postWithToken('/tags/delete/' . $canonical, ['remove' => '1'])->status);
        $this->assertSame([], $this->ids('woodlnd'));
    }

    public function test_nested_authoring_rollback_restores_search_and_shared_words_survive_until_last_posting(): void
    {
        [$first, $slug] = $this->create('Sharedcanary First');
        [$second] = $this->create('Sharedcanary Second');
        $this->db->begin();
        try {
            $this->update($slug, 'Temporarycanary First');
            $this->assertSame([$first], $this->ids('temporarycanry'));
            $this->assertSame([$second], $this->ids('sharedcanry'));
        } finally { $this->db->rollBack(); }
        $this->assertSame([], $this->ids('temporarycanry'));
        $this->assertEqualsCanonicalizing([$first, $second], $this->ids('sharedcanry'));
        $this->db->query('DELETE FROM stories WHERE id=?', [$first]);
        $this->assertSame([$second], $this->ids('sharedcanry'));
        $this->db->query('DELETE FROM stories WHERE id=?', [$second]);
        $this->assertSame([], $this->ids('sharedcanry'));
        $this->assertNull($this->db->one('SELECT word FROM search_words WHERE word=?', ['sharedcanary']));
        $this->assertSame([], $this->db->all('SELECT word FROM search_deletes WHERE word=?', ['sharedcanary']));
    }

    public function test_direct_alias_and_canonical_attachments_count_one_story_and_rebuild_identically(): void
    {
        $canonical = $this->archive->tag('Slow Burn');
        $alias = $this->archive->tag('Slowburn', 'Search Fixture', $canonical);
        [$story] = $this->create('Wonderland', [$canonical]);
        // Historical imports can retain the synonym ID beside its canonical ID.
        $this->db->query('INSERT INTO story_tags(story_id,tag_id) VALUES (?,?)', [$story, $alias]);
        $before = $this->search->searchWithTaxonomies('slowbrun', ['tag' => [$canonical]], 20, 0, 0);
        $this->assertSame([$story], array_column($before['rows'], 'id'));
        $this->assertSame(1, $before['total']);
        $migration = require dirname(__DIR__) . '/app/Features/Search/migrations/071_search_words.php';
        $migration->down($this->db);
        $migration->up($this->db);
        $this->assertSame($before, $this->search->searchWithTaxonomies('slowbrun', ['tag' => [$canonical]], 20, 0, 0));
        $this->assertSame([$story], $this->ids('wonderlend'));
    }

    public function test_sixty_four_character_query_reaches_sixty_five_candidate_but_longer_query_is_exact_only(): void
    {
        $word = 'a' . str_repeat('b', 63) . 'c';
        [$story] = $this->create($word);
        $this->assertSame([$story], $this->ids('a' . str_repeat('b', 62) . 'c'));
        $this->assertSame([$story], $this->ids($word));
        $this->assertSame([], $this->ids('a' . str_repeat('b', 31) . 'x' . str_repeat('b', 31) . 'c'));
        $longer = str_repeat('d', 66);
        [$exact] = $this->create($longer);
        $this->assertSame([$exact], $this->ids($longer));
        $this->assertSame([], $this->ids(str_repeat('d', 32) . 'x' . str_repeat('d', 32)));
    }

    public function test_repeated_letters_do_not_allow_a_wrong_deletion_position_to_match(): void
    {
        [$repeat] = $this->create('Bookkeeper');
        $this->assertSame([$repeat], $this->ids('boookkeeper'));
        $this->assertSame([$repeat], $this->ids('bokkeeper'));
        [$other] = $this->create('Abcdef');
        $this->assertSame([$other], $this->ids('abxdef'));
        // Both words can delete to "abdef", but at different positions: two edits, not one.
        $this->assertSame([], $this->ids('abdecf'));
    }
}
