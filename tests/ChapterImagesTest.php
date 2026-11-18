<?php // tests/ChapterImagesTest.php
namespace App\Tests;
use App\ChapterImages;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class ChapterImagesTest extends TestCase
{
    private const A = '/uploads/0123456789abcdef.png';
    private const B = '/uploads/fedcba9876543210.jpg';

    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-chimg-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) if (is_file($f)) @unlink($f);
    }

    private function owns(int $user, string $src): void
    {
        $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, upload_key, created_at) VALUES (?, ?, ?, NULL, ?)',
            [$user, $src, hash('sha256', $src), date('c')]);
    }

    public function test_it_reads_each_library_image_occurrence_with_its_alt(): void
    {
        $md = "One ![a cat](" . self::A . ") and ![](" . self::B . ") and ![x](https://example.test/x.png)";
        $this->assertSame([['alt' => 'a cat', 'src' => self::A], ['alt' => '', 'src' => self::B]], ChapterImages::sources($md));
    }

    public function test_an_editor_placeholder_that_never_reached_storage_is_unresolved(): void
    {
        $this->assertTrue(ChapterImages::hasUnresolved('![x](epigraph-pending:abc)'));
        $this->assertTrue(ChapterImages::hasUnresolved('![x]( BLOB:http://a/b)'));
        $this->assertFalse(ChapterImages::hasUnresolved('![x](' . self::A . ')'));
    }

    public function test_a_second_copy_of_a_stored_image_counts_as_added_but_the_first_does_not(): void
    {
        $stored = '![a](' . self::A . ')';
        $added = ChapterImages::added($stored, '![a](' . self::A . ') ![a](' . self::A . ')');
        $this->assertSame([['alt' => 'a', 'src' => self::A]], $added);
    }

    public function test_unchanged_references_pass_even_when_images_are_off_and_not_owned(): void
    {
        $text = '![a](' . self::A . ')';
        $this->assertNull(ChapterImages::refusal($this->db, 1, $text, $text . ' more words', false));
    }

    public function test_a_new_image_is_refused_in_order_pending_off_alt_then_library(): void
    {
        $this->assertSame('chapter.err_image_pending', ChapterImages::refusal($this->db, 1, '', '![x](blob:z)', true));
        $this->assertSame('chapter.err_image_off', ChapterImages::refusal($this->db, 1, '', '![x](' . self::A . ')', false));
        $this->assertSame('chapter.err_image_alt', ChapterImages::refusal($this->db, 1, '', '![ ](' . self::A . ')', true));
        $this->assertSame('chapter.err_image_library', ChapterImages::refusal($this->db, 1, '', '![x](' . self::A . ')', true));
    }

    public function test_a_new_image_passes_only_from_the_authors_own_library(): void
    {
        $this->owns(1, self::A);
        $this->assertNull(ChapterImages::refusal($this->db, 1, '', '![x](' . self::A . ')', true));
        $this->assertSame('chapter.err_image_library', ChapterImages::refusal($this->db, 2, '', '![x](' . self::A . ')', true));
    }

    public function test_one_unowned_image_among_owned_ones_refuses_the_whole_save(): void
    {
        $this->owns(1, self::A);
        $this->assertSame('chapter.err_image_library',
            ChapterImages::refusal($this->db, 1, '', '![x](' . self::A . ') ![y](' . self::B . ')', true));
    }
}
