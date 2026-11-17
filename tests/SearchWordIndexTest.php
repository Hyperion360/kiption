<?php
declare(strict_types=1);
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Search\Spelling;
use App\Tests\Support\{SearchArchive, TaxonomyCase};
use Kip\Migrations\Migration;

#[Small]
final class SearchWordIndexTest extends TaxonomyCase
{
    private SearchArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $rating = (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        $this->archive = new SearchArchive($this->db, $this->authorId, $rating);
        $this->archive->clear();
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__) . '/app/Features/Search/migrations/071_search_words.php';
    }

    private function posts(string $word): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT DISTINCT story_id FROM search_word_posts WHERE word = ? ORDER BY story_id', [$word]), 'story_id'));
    }

    private function retained(string $word, string $table = 'stories_fts'): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT rowid FROM ' . $table . ' WHERE ' . $table . ' MATCH ? ORDER BY rowid', [$word]), 'rowid'));
    }

    public function test_direct_story_writes_populate_words_and_position_keys(): void
    {
        $story = $this->archive->story('The Wonderland Tale');
        $this->assertNotNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'search_words'"));
        $this->assertSame([$story], $this->posts('wonderland'));
        $this->assertNotNull($this->db->one("SELECT word FROM search_deletes WHERE key = 'wonderlnd' AND position = 8 AND word = 'wonderland'"));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_word_source'));
    }

    public function test_title_update_removes_unique_words_but_keeps_shared_words(): void
    {
        $first = $this->archive->story('Sharedword Firstword');
        $second = $this->archive->story('Sharedword Secondword');
        $this->db->query('UPDATE stories SET title = ? WHERE id = ?', ['Revisedword', $first]);
        $this->assertSame([$first], $this->posts('revisedword'));
        $this->assertSame([$second], $this->posts('sharedword'));
        $this->assertNull($this->db->one("SELECT word FROM search_words WHERE word = 'firstword'"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM search_deletes WHERE word = 'firstword'"));
    }

    public function test_story_delete_cascades_postings_and_unused_deletions(): void
    {
        $story = $this->archive->story('Uniquegoneword');
        $this->db->query('DELETE FROM stories WHERE id = ?', [$story]);
        $this->assertSame([], $this->posts('uniquegoneword'));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_words'));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_deletes'));
        $this->assertSame([], $this->db->all('PRAGMA foreign_key_check'));
    }

    public function test_transaction_rollback_restores_all_index_relations(): void
    {
        $story = $this->archive->story('Originalword');
        $this->db->begin();
        $this->db->query('UPDATE stories SET title = ? WHERE id = ?', ['Quarantinedword', $story]);
        $this->assertSame([$story], $this->posts('quarantinedword'));
        $this->db->rollBack();
        $this->assertSame([$story], $this->posts('originalword'));
        $this->assertSame([], $this->posts('quarantinedword'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM search_deletes WHERE word = 'quarantinedword'"));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_word_source'));
    }

    public function test_direct_and_synonym_attachments_preserve_field_identity(): void
    {
        $canonical = $this->archive->tag('Canonicalword');
        $alias = $this->archive->tag('Aliasword', 'Search Fixture', $canonical);
        $story = $this->archive->story('Canonicalword', ['tags' => [$canonical]]);
        $this->assertSame([$story], $this->posts('aliasword'));
        $this->assertSame(['tags', 'title'], array_column($this->db->all(
            "SELECT field FROM search_word_posts WHERE word = 'canonicalword' AND story_id = ? ORDER BY field", [$story]), 'field'));
        $this->db->query('DELETE FROM story_tags WHERE story_id = ? AND tag_id = ?', [$story, $canonical]);
        $this->assertSame([], $this->posts('aliasword'));
        $this->assertSame([$story], $this->posts('canonicalword'));
        $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$story, $alias]);
        $this->assertSame([$story], $this->posts('aliasword'));
    }

    public function test_deleted_synonym_disappears_from_all_three_indexes(): void
    {
        $canonical = $this->archive->tag('Reviewcanonical');
        $story = $this->archive->story('Neutral Story', ['tags' => [$canonical]]);
        $alias = $this->archive->tag('Zebraquillcanary', 'Search Fixture', $canonical);
        $this->assertSame([$story], $this->posts('zebraquillcanary'));
        foreach (['stories_fts', 'stories_trigram'] as $table) {
            $this->assertSame([$story], $this->retained('zebraquillcanary', $table));
        }
        $this->db->query('DELETE FROM tags WHERE id = ?', [$alias]);
        $this->assertSame([], $this->posts('zebraquillcanary'));
        foreach (['stories_fts', 'stories_trigram'] as $table) {
            $this->assertSame([], $this->retained('zebraquillcanary', $table));
        }
    }

    public function test_canonical_delete_removes_attached_tag_and_alias_words(): void
    {
        $canonical = $this->archive->tag('Reviewcanonical');
        $alias = $this->archive->tag('Aliascanary', 'Search Fixture', $canonical);
        $this->archive->story('Neutral Story', ['tags' => [$canonical]]);
        // Aliases reference the canonical with a restrictive foreign key.
        $this->db->query('DELETE FROM tags WHERE id = ?', [$alias]);
        $this->db->query('DELETE FROM tags WHERE id = ?', [$canonical]);
        foreach (['reviewcanonical', 'aliascanary'] as $word) {
            $this->assertSame([], $this->posts($word));
            $this->assertSame([], $this->retained($word));
            $this->assertSame([], $this->retained($word, 'stories_trigram'));
        }
    }

    public function test_synonym_rename_retarget_and_rollback_follow_current_attachments(): void
    {
        $canonical = $this->archive->tag('Reviewcanonical');
        $other = $this->archive->tag('Othercanonical');
        $first = $this->archive->story('First Story', ['tags' => [$canonical]]);
        $second = $this->archive->story('Second Story', ['tags' => [$other]]);
        $alias = $this->archive->tag('Zebraquillcanary', 'Search Fixture', $canonical);
        $this->db->begin();
        $this->db->query('UPDATE tags SET name = ? WHERE id = ?', ['Forestcanary', $alias]);
        $this->assertSame([], $this->posts('zebraquillcanary'));
        $this->assertSame([$first], $this->posts('forestcanary'));
        $this->db->rollBack();
        $this->assertSame([$first], $this->posts('zebraquillcanary'));
        $this->db->query('UPDATE tags SET canonical_id = ? WHERE id = ?', [$other, $alias]);
        $this->assertSame([$second], $this->posts('zebraquillcanary'));
        $this->db->query('UPDATE tags SET canonical_id = NULL WHERE id = ?', [$alias]);
        $this->assertSame([], $this->posts('zebraquillcanary'));
    }

    public function test_down_up_backfills_existing_rows_and_can_repeat(): void
    {
        $migration = $this->migration();
        $migration->down($this->db);
        $tag = $this->archive->tag('Backfilltag');
        $story = $this->archive->story('Wonderland Backfill', ['tags' => [$tag]]);
        $migration->up($this->db);
        $this->assertSame([$story], $this->posts('wonderland'));
        $this->assertSame([$story], $this->posts('backfilltag'));
        $migration->down($this->db);
        $migration->up($this->db);
        $this->assertSame([$story], $this->posts('wonderland'));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_word_source'));
    }

    public function test_up_repairs_already_stale_retained_indexes(): void
    {
        $migration = $this->migration();
        $migration->down($this->db);
        $canonical = $this->archive->tag('Reviewcanonical');
        $story = $this->archive->story('Neutral Story', ['tags' => [$canonical]]);
        foreach (['stories_fts' => 'stories_fts_tags_del', 'stories_trigram' => 'stories_tri_tags_del'] as $table => $trigger) {
            $this->db->query('DROP TRIGGER ' . $trigger);
            $this->db->query('CREATE TRIGGER ' . $trigger . ' BEFORE DELETE ON tags BEGIN DELETE FROM ' . $table
                . ' WHERE rowid IN (SELECT story_id FROM story_tags WHERE tag_id = old.id); END');
        }
        $alias = $this->archive->tag('Zebraquillcanary', 'Search Fixture', $canonical);
        $this->db->query('DELETE FROM tags WHERE id = ?', [$alias]);
        $this->assertSame([$story], $this->retained('zebraquillcanary'));
        $migration->up($this->db);
        $this->assertSame([], $this->posts('zebraquillcanary'));
        $this->assertSame([], $this->retained('zebraquillcanary'));
        $this->assertSame([], $this->retained('zebraquillcanary', 'stories_trigram'));
        $migration->down($this->db);
        $alias = $this->archive->tag('Freshcanary', 'Search Fixture', $canonical);
        $this->db->query('DELETE FROM tags WHERE id = ?', [$alias]);
        $this->assertSame([], $this->retained('freshcanary'));
        $this->assertSame([], $this->retained('freshcanary', 'stories_trigram'));
    }

    public function test_missing_fts_or_an_ordinary_table_is_a_noop(): void
    {
        $migration = $this->migration();
        $migration->down($this->db);
        $this->db->query('DROP TABLE stories_fts');
        $migration->up($this->db);
        $this->assertNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'search_words'"));
        $this->db->query('CREATE TABLE stories_fts (title TEXT)');
        $migration->up($this->db);
        $this->assertNull($this->db->one("SELECT name FROM sqlite_master WHERE name = 'search_words'"));
        $migration->down($this->db);
    }

    public function test_missing_optional_trigram_does_not_prevent_spelling_index(): void
    {
        $migration = $this->migration();
        $migration->down($this->db);
        $trigram = require dirname(__DIR__) . '/app/Features/Search/migrations/069_search_trigram.php';
        $trigram->down($this->db);
        $story = $this->archive->story('Wonderland');
        $migration->up($this->db);
        $this->assertSame([$story], $this->posts('wonderland'));
    }

    public function test_approval_and_merge_index_names_while_pending_and_rejected_names_stay_out(): void
    {
        $target = $this->archive->tag('Existingcanonical');
        $type = (int) $this->db->one('SELECT tag_type_id FROM tags WHERE id = ?', [$target])['tag_type_id'];
        $story = $this->archive->story('Neutral Story');
        foreach (['Approveword', 'Mergeword', 'Rejectword'] as $name) {
            $this->db->query('INSERT INTO tag_suggestions (tag_type_id, name, suggested_by, story_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$type, $name, $this->authorId, $story, 'pending', date('c')]);
            $this->assertSame([], $this->posts(strtolower($name)));
        }
        foreach (['approve' => 'Approveword', 'merge' => 'Mergeword', 'reject' => 'Rejectword'] as $action => $name) {
            $response = $this->admin()->postWithToken('/tagqueue/' . $action,
                ['type_id' => (string) $type, 'name' => $name, 'target_id' => (string) $target]);
            $this->assertSame(302, $response->status, $response->body);
        }
        $this->assertSame([$story], $this->posts('approveword'));
        $this->assertSame([$story], $this->posts('mergeword'));
        $this->assertSame([$story], $this->posts('existingcanonical'));
        $this->assertSame([], $this->posts('rejectword'));
        $this->assertSame([], $this->retained('rejectword'));
        $this->assertSame(0, $this->n('SELECT COUNT(*) c FROM search_word_source'));
    }

    public function test_unstemmed_unicode_words_and_deletion_lengths_match_probe_contract(): void
    {
        $long = str_repeat('界', 65);
        $tooLong = str_repeat('字', 66);
        $story = $this->archive->story("café cafe\u{0301} ПРИВЕТ 東京 don't rivers 123 two-words $long $tooLong");
        foreach (['café', 'привет', '東京', 'don', 't', 'rivers', '123', 'two', 'words', $long] as $word) {
            $this->assertSame([$story], $this->posts($word), $word);
        }
        $this->assertSame([], $this->posts($tooLong));
        $this->assertSame(65, (int) $this->db->one('SELECT COUNT(*) c FROM search_deletes WHERE word = ?', [$long])['c']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) c FROM search_deletes WHERE word = '東京'"));
        $this->assertContains('cafe', Spelling::probes("cafe\u{0301}")['tokens']);
        $this->assertSame([$story], $this->posts("cafe\u{0301}"));
        $this->assertSame([], $this->posts('cafe'), 'Combining-mark normalization is not claimed by the spelling index.');
    }
}
