<?php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\{ChapterChanged, ChapterRevision};
use PHPUnit\Framework\TestCase;

#[Small]
final class ChapterRevisionTest extends TestCase
{
    public function test_each_saved_field_and_stable_id_change_the_revision(): void
    {
        $row = ['chapter_id' => 9, 'title' => 'T', 'content' => 'A', 'notes_before' => '',
            'notes_after' => '', 'publish_at' => null, 'access_hash' => null];
        $base = ChapterRevision::of($row);
        foreach (array_keys($row) as $field) {
            $next = $row;
            $next[$field] = $field === 'chapter_id' ? 10 : 'changed';
            $this->assertNotSame($base, ChapterRevision::of($next), $field);
        }
        $this->assertSame($base, ChapterRevision::of(array_reverse($row, true)));
        $this->assertSame($base, ChapterRevision::of($row + ['position' => 20, 'story_title' => 'Ignored']));
        $this->assertSame($base, ChapterRevision::of(array_replace($row, ['chapter_id' => '9'])));
        $this->assertNotSame($base, ChapterRevision::of(array_replace($row, ['publish_at' => ''])));
    }

    public function test_conflict_carries_safe_current_fields_and_exact_revision(): void
    {
        $current = ['chapter_id' => 42, 'title' => 'T', 'content' => 'Current', 'notes_before' => '',
            'notes_after' => '', 'publish_at' => null, 'access_hash' => 'private-hash', 'recovery_protected' => 1,
            'unrelated_secret' => 'hidden'];
        $exception = new ChapterChanged($current);
        $this->assertSame('Current', $exception->savedFields()['content']);
        $this->assertSame(1, $exception->savedFields()['recovery_protected']);
        $this->assertArrayNotHasKey('access_hash', $exception->savedFields());
        $this->assertArrayNotHasKey('unrelated_secret', $exception->savedFields());
        $this->assertSame(ChapterRevision::of($current), $exception->savedRevision());
    }
    public function test_distinct_legacy_text_bytes_never_collapse_or_block_editing(): void
    {
        $row = ['chapter_id' => 9, 'content' => "Legacy \xff byte"];
        $first = ChapterRevision::of($row);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        $this->assertSame($first, ChapterRevision::of($row));
        $this->assertNotSame($first, ChapterRevision::of(array_replace($row, ['content' => "Legacy \xfe byte"])));
        $this->assertNotSame($first, ChapterRevision::of(array_replace($row, ['content' => 'Legacy � byte'])));
    }
}
