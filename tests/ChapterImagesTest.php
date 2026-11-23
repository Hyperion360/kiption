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

    public function test_the_same_new_image_used_twice_needs_only_one_library_row(): void
    {
        $this->owns(1, self::A);
        $md = '![one](' . self::A . ') and ![two](' . self::A . ')';
        $this->assertNull(ChapterImages::refusal($this->db, 1, '', $md, true));
    }

    public function test_a_long_chapter_of_new_images_is_checked_whole(): void
    {
        $srcs = [];
        for ($i = 0; $i < 150; $i++) { $srcs[] = sprintf('/uploads/%016x.png', $i + 1); }
        foreach ($srcs as $s) $this->owns(1, $s);
        $md = implode("\n", array_map(static fn($s) => "![pic]($s)", $srcs));
        $this->assertNull(ChapterImages::refusal($this->db, 1, '', $md, true));
        $this->db->query('DELETE FROM author_images WHERE path = ?', [$srcs[149]]);
        $this->assertSame('chapter.err_image_library', ChapterImages::refusal($this->db, 1, '', $md, true));
    }

    public function test_missing_lists_each_distinct_upload_that_has_no_file(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-chmiss-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/0123456789abcdef.png', 'x');
        $md = '![a](' . self::A . ') ![b](' . self::B . ') ![c](' . self::B . ') ![d](https://example.test/x.png)';
        $this->assertSame([self::B], ChapterImages::missing($dir, $md));
        $this->assertSame([], ChapterImages::missing($dir, 'No images, just words.'));
        $this->assertSame([], ChapterImages::missing($dir, '![a](' . self::A . ')'));
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function test_a_publish_is_refused_for_a_missing_file_but_a_draft_is_not(): void
    {
        $dir = sys_get_temp_dir() . '/kiption-chmiss2-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/0123456789abcdef.png', 'x');
        $app = new \Kip\App(['app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod', 'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite::memory:'], 'log_db' => ['dsn' => 'sqlite::memory:'], 'uploads' => ['dir' => $dir]]);
        $broken = '![b](' . self::B . ')';
        $this->assertNull(\App\ChapterChecks::missingImageError($app, $broken, true), 'a draft keeps its broken image');
        $this->assertStringContainsString(self::B, (string) \App\ChapterChecks::missingImageError($app, $broken, false));
        $this->assertNull(\App\ChapterChecks::missingImageError($app, '![a](' . self::A . ')', false));
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
