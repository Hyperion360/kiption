<?php // tests/EpigraphBundleTest.php
namespace App\Tests;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The vendored Epigraph bundle is pinned by digest, loads only behind
 *  editor_formatted, and leaves the plain textarea untouched. */
final class EpigraphBundleTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-epigraph-') . '.sqlite';
        $db = new \Kip\Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function box(bool $formatted): \DOMElement
    {
        $app = new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->path . '.mail', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-epigraph-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'editor_formatted' => $formatted,
        ]);
        $html = (new TestClient($app))->actingAs(1)->get('/chapter/new/the-rabbit-hole')->body;
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $box = (new \DOMXPath($doc))->query('//textarea[@name="content"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $box);
        return $box;
    }

    public function test_the_pinned_digest_matches_the_vendored_file(): void
    {
        $dir = dirname(__DIR__) . '/public/assets';
        $this->assertFileExists($dir . '/epigraph.js');
        $line = trim((string) file_get_contents($dir . '/epigraph.js.sha256'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}  epigraph\.js$/D', $line);
        $this->assertSame(substr($line, 0, 64), hash_file('sha256', $dir . '/epigraph.js'));
        $this->assertStringNotContainsString('/Users/', (string) file_get_contents($dir . '/epigraph.js'));
    }

    public function test_image_uploads_are_offered_only_when_the_site_switch_is_on(): void
    {
        $this->assertFalse($this->box(true)->hasAttribute('data-epigraph-upload-url'));
        \App\Features::init(new \Kip\Database('sqlite:' . $this->path), []);
        \App\Settings::put('chapter_images_on', '1');
        $box = $this->box(true);
        $this->assertSame('/authorimages/upload', $box->getAttribute('data-epigraph-upload-url'));
        $this->assertNotSame('', $box->getAttribute('data-epigraph-csrf'));
        $this->assertFalse($this->box(false)->hasAttribute('data-epigraph-upload-url'));
    }

    public function test_the_editor_loads_only_when_the_formatted_flag_is_on(): void
    {
        $off = $this->box(false);
        $this->assertSame('paste editor', $off->getAttribute('data-js-module'));
        $this->assertTrue($off->hasAttribute('required'));
        $on = $this->box(true);
        $this->assertSame('paste epigraph-boot', $on->getAttribute('data-js-module'));
        $this->assertTrue($on->hasAttribute('required'), 'the plain form stays valid without scripting');
        $this->assertFalse($on->hasAttribute('hidden'), 'the server never hides the textarea');
        $config = (string) file_get_contents(dirname(__DIR__) . '/config.php');
        $this->assertStringContainsString("'editor_formatted' => false", $config);
    }

    public function test_the_loader_lists_the_epigraph_files_in_order(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("['epigraph-boot', ['paste', 'epigraph']]", $js);
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/epigraph-boot.js');
    }
}
