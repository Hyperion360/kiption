<?php // app/Features/Look/Tests/CompilerTest.php
namespace App\Features\Look\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Look\{Boot, Compiler, Store};
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

#[Medium]
final class CompilerTest extends TestCase
{
    private string $dir = '';
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-look-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        \App\Features::reset();
        Boot::reset();
        if ($this->dbPath !== '') { @unlink($this->dbPath); @unlink($this->dbPath . '-wal'); @unlink($this->dbPath . '-shm'); @unlink(substr($this->dbPath, 0, -7)); }
    }

    private function values(array $over = []): array
    {
        return $over + ['accent' => '#b3261e', 'bg' => '#ffffff', 'ink' => '#111111',
            'width' => 'wide', 'size' => 'large', 'density' => 'roomy', 'header' => 'plain'];
    }

    private function db(): Database
    {
        $this->dbPath = \App\Tests\Support\SchemaTemplate::path('kiption-look-');
        $db = new Database('sqlite:' . $this->dbPath);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Features::init($db, []);
        return $db;
    }

    public function test_css_contains_light_night_and_media_blocks_and_only_allowed_characters(): void
    {
        $css = Compiler::css($this->values());
        $this->assertStringContainsString(':root{', $css);
        $this->assertStringContainsString('html[data-theme="night"]{', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme:dark){html:not([data-theme="paper"]):not([data-theme="sepia"]){', $css);
        $this->assertStringContainsString('--read-width:720px', $css);
        $this->assertStringContainsString('color-scheme:dark;', $css);
        $this->assertStringContainsString('.site-head{border-block-end-color:transparent}', $css);
        $this->assertDoesNotMatchRegularExpression('/url\(|@import|[<>\\\\]|expression/i', $css);
        $this->assertMatchesRegularExpression('/^[\x20-\x7e\n]+$/D', $css);
    }

    public function test_default_colors_emit_no_palette_block(): void
    {
        $css = Compiler::css($this->values(['accent' => '#1f4f42', 'bg' => '#f8f7f3', 'ink' => '#1c2320']));
        $this->assertStringNotContainsString('--accent', $css);
        $this->assertStringContainsString('--read-width:720px', $css);
    }

    public function test_all_defaults_emit_only_the_revision_comment(): void
    {
        $css = Compiler::css(Compiler::defaults());
        $this->assertMatchesRegularExpression('/^\/\* look [a-f0-9]{10} \*\/\n?$/D', $css);
    }

    public function test_revision_changes_with_values_and_with_compiler_version(): void
    {
        $a = Compiler::revision($this->values());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{10}$/D', $a);
        $this->assertNotSame($a, Compiler::revision($this->values(['width' => 'narrow'])));
        $this->assertNotSame($a, Compiler::revision($this->values(), Compiler::VERSION + 1), 'the compiler version feeds the hash');
        $reordered = array_reverse($this->values(), true);
        $this->assertSame($a, Compiler::revision($reordered), 'key order must not change the revision');
    }

    public function test_default_colors_emit_no_palette_block_whatever_the_key_order(): void
    {
        $css = Compiler::css(array_reverse(Compiler::defaults(), true));
        $this->assertStringNotContainsString('--accent', $css);
    }

    public function test_write_leaves_no_temp_files(): void
    {
        $this->assertTrue(Compiler::write($this->values(), $this->dir));
        $this->assertSame(['look.css'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
        $this->assertSame(Compiler::css($this->values()), file_get_contents($this->dir . '/look.css'));
    }

    public function test_write_to_an_unwritable_place_returns_false_and_does_not_throw(): void
    {
        $this->assertFalse(Compiler::write($this->values(), '/proc/nope/look'));
        // a path whose parent is a plain file cannot become a folder either
        file_put_contents($this->dir . '.file', 'x');
        $this->assertFalse(Compiler::write($this->values(), $this->dir . '.file/sub'));
        @unlink($this->dir . '.file');
    }

    public function test_write_fails_cleanly_when_the_target_is_a_directory(): void
    {
        mkdir($this->dir . '/look.css', 0775, true); // rename onto a directory fails
        $this->assertFalse(Compiler::write($this->values(), $this->dir));
        $this->assertSame(['look.css'], array_values(array_diff(scandir($this->dir), ['.', '..'])), 'the temp file was removed');
    }

    public function test_the_file_name_is_fixed_whatever_the_values(): void
    {
        Compiler::write($this->values(), $this->dir);
        $this->assertFileExists($this->dir . '/look.css');
        $this->assertCount(1, glob($this->dir . '/*'));
    }

    public function test_dir_prefers_the_look_dir_then_the_data_dir_then_the_app_folder(): void
    {
        $this->assertSame('/a/look', Boot::dir(['site_look_dir' => '/a/look', 'data_dir' => '/b']));
        $this->assertSame('/b/site-look', Boot::dir(['data_dir' => '/b/']));
        $this->assertStringEndsWith('/app/site-look', Boot::dir([]));
    }

    public function test_store_round_trips_and_refuses_a_bad_row(): void
    {
        $db = $this->db();
        $this->assertNull(Store::get());
        Store::put($this->values());
        $this->assertSame($this->values(), Store::get());
        $db->query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['site_look', json_encode($this->values(['accent' => 'red']))]);
        \App\Features::init($db, []);
        $this->assertNull(Store::get(), 'a bad color never reaches the compiler');
        $db->query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['site_look', 'not json']);
        \App\Features::init($db, []);
        $this->assertNull(Store::get());
        $db->query('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', ['site_look', '"a string"']);
        \App\Features::init($db, []);
        $this->assertNull(Store::get());
    }

    public function test_store_clear_removes_the_row_and_put_still_works_after(): void
    {
        $db = $this->db();
        Store::put($this->values());
        Store::clear();
        $this->assertSame(0, (int) $db->one("SELECT COUNT(*) c FROM settings WHERE key = 'site_look'")['c']);
        Store::put($this->values(['width' => 'narrow']));
        $this->assertSame('narrow', Store::get()['width']);
    }

    public function test_boot_href_is_empty_until_a_look_is_saved_and_survives_an_unready_database(): void
    {
        $this->db();
        Boot::init();
        $this->assertSame('', Boot::href());
        Store::put($this->values());
        Boot::init();
        $this->assertSame('/look/css?v=' . Compiler::revision($this->values()), Boot::href());
        Boot::reset();
        $this->assertSame('', Boot::href());
    }
}
