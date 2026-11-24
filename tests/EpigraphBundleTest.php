<?php // tests/EpigraphBundleTest.php
namespace App\Tests;
use Kip\App;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The vendored Epigraph bundle is pinned by digest, is the only editor,
 *  and leaves the plain textarea untouched. */
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

    private function box(): \DOMElement
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
        ]);
        $client = (new TestClient($app))->actingAs(1);
        \App\SessionGuard::enforce(new \Kip\Database('sqlite:' . $this->path), $client->session); // what index.php does before a page runs
        $html = $client->get('/chapter/new/the-rabbit-hole')->body;
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
        $this->assertFalse($this->box()->hasAttribute('data-epigraph-upload-url'));
        \App\Features::init(new \Kip\Database('sqlite:' . $this->path), []);
        \App\Settings::put('chapter_images_on', '1');
        $box = $this->box();
        $this->assertSame('/authorimages/upload', $box->getAttribute('data-epigraph-upload-url'));
        $this->assertNotSame('', $box->getAttribute('data-epigraph-csrf'));
    }

    public function test_with_images_on_the_box_carries_the_intake_size_cap_and_no_off_note(): void
    {
        \App\Features::init(new \Kip\Database('sqlite:' . $this->path), []);
        \App\Settings::put('chapter_images_on', '1');
        $box = $this->box();
        $this->assertSame('10485760', $box->getAttribute('data-epigraph-max-bytes'));
        $this->assertFalse($box->hasAttribute('data-epigraph-images-off'));
    }

    public function test_with_images_off_an_author_is_told_the_administrator_has_not_turned_them_on(): void
    {
        $box = $this->box();
        $this->assertSame(\App\Lang::t('chapter.images_off_author'), $box->getAttribute('data-epigraph-images-off'));
        $this->assertFalse($box->hasAttribute('data-epigraph-max-bytes'));
    }

    public function test_with_images_off_an_administrator_is_told_which_setting_to_turn_on(): void
    {
        \App\Adminness::setRole(new \Kip\Database('sqlite:' . $this->path), 1, 'admin');
        $this->assertSame(\App\Lang::t('chapter.images_off_admin'), $this->box()->getAttribute('data-epigraph-images-off'));
    }

    public function test_epigraph_is_the_only_editor_and_the_plain_textarea_is_the_scriptless_fallback(): void
    {
        $box = $this->box();
        $this->assertSame('paste epigraph-boot', $box->getAttribute('data-js-module'));
        $this->assertTrue($box->hasAttribute('required'), 'the plain form stays valid without scripting');
        $this->assertFalse($box->hasAttribute('hidden'), 'the server never hides the textarea');
        $this->assertStringNotContainsString('editor_formatted', (string) file_get_contents(dirname(__DIR__) . '/config.php'));
    }

    public function test_the_loader_lists_the_epigraph_files_in_order(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $this->assertStringContainsString("['epigraph-boot', ['paste', 'epigraph']]", $js);
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/epigraph-boot.js');
    }
}
