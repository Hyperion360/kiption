<?php // app/Features/Compare/Tests/MarketingPathTest.php
namespace App\Features\Compare\Tests;
use App\StaticCache\{Builder, Cache};
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Migrations\Migrator;
use Kip\Storage;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class MarketingPathTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private string $cacheDir = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-mkt-') . '.sqlite';
        $this->uploads = sys_get_temp_dir() . '/kiption-mkt-upl-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/kiption-mkt-cache-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        foreach (glob($this->uploads . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->uploads);
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    /** The MetadataTest harness, minus actingAs: marketing paths are guest
     *  pages, and the guest render is the variant the static layer stores. */
    private function client(): TestClient
    {
        $app = new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-mkt-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $app->container->instance(Storage::class,
            new Storage($this->uploads, 2097152, ['png', 'jpg', 'jpeg', 'webp', 'gif'], mover: fn(string $t, string $d): bool => copy($t, $d)));
        return new TestClient($app);
    }

    /** Case-insensitive header read (RFC 9110 5.1), the App::header stance. */
    private function header(Response $res, string $name): ?string
    {
        foreach ($res->headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) return is_array($v) ? (string) $v[0] : (string) $v;
        }
        return null;
    }

    private function seedPage(string $slug, string $title, string $body): void
    {
        $this->db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)', [$slug, $title, $body]);
    }

    public function test_cloud_serves_its_page_row_at_the_top_level(): void
    {
        $this->seedPage('cloud', 'Kiption Cloud', 'Hosted fiction archives for publishers, hosted at cloud.kiption.com.');
        $res = $this->client()->get('/cloud');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Hosted fiction archives for publishers', $res->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/cloud">', $res->body);
    }

    public function test_cloud_without_a_row_is_a_404(): void
    {
        $this->assertSame(404, $this->client()->get('/cloud')->status);
    }

    public function test_comparison_serves_hyphen_free_action_paths_only(): void
    {
        // The slug keeps its hyphen; the PATH does not: the router hands the
        // action segment to method_exists() raw (Router.php:69), and a PHP
        // method name cannot carry one.
        $this->seedPage('compare-royal-road', 'Kiption vs Royal Road', 'The self-hosted archive and the serial platform compared.');
        $res = $this->client()->get('/compare/royalroad');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Kiption vs Royal Road', $res->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/compare/royalroad">', $res->body);
        $this->assertSame(404, $this->client()->get('/compare/royal-road')->status, 'the hyphenated action never resolves');
        $this->assertSame(404, $this->client()->get('/compare/unknownthing')->status);
    }

    public function test_substack_comparison_serves_at_top_level(): void
    {
        $this->seedPage('compare-substack', 'Kiption vs Substack', 'A reading archive and a newsletter platform are different tools.');
        $res = $this->client()->get('/compare/substack');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('A reading archive and a newsletter platform', $res->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/compare/substack">', $res->body);
    }

    public function test_dmca_serves_at_top_level(): void
    {
        // The migration-seeded row (037) carries the takedown body.
        $res = $this->client()->get('/dmca');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('Copyright notice and takedown policy', $res->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/dmca">', $res->body);
    }

    public function test_compare_index_lists_all_seven_with_fallback_titles(): void
    {
        $this->seedPage('compare-substack', 'Kiption vs Substack', 'Body.');
        $res = $this->client()->get('/compare');
        $this->assertSame(200, $res->status, $res->body);
        // A seeded row links with its own title.
        $this->assertStringContainsString('<a href="/compare/substack">Kiption vs Substack</a>', $res->body);
        // A row-less comparison degrades to the slug-as-title, not a failure.
        $this->assertStringContainsString('<a href="/compare/royalroad">Royal road</a>', $res->body);
        foreach (['/compare/efiction', '/compare/wattpad', '/compare/webnovel', '/compare/royalroad',
                  '/compare/fanfictionnet', '/compare/ao3', '/compare/substack'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $res->body, $href);
        }
    }

    public function test_page_view_redirects_mapped_slugs_to_the_top_level(): void
    {
        $this->seedPage('compare-substack', 'Kiption vs Substack', 'Body.');
        $res = $this->client()->get('/page/view/compare-substack');
        $this->assertSame(301, $res->status);
        $this->assertSame('/compare/substack', $this->header($res, 'Location'));
        $res = $this->client()->get('/page/view/dmca');
        $this->assertSame(301, $res->status);
        $this->assertSame('/dmca', $this->header($res, 'Location'));
    }

    public function test_unmapped_slug_still_serves_at_page_view(): void
    {
        $res = $this->client()->get('/page/view/about');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/page/view/about">', $res->body);
    }

    public function test_empty_body_mapped_page_is_noindex(): void
    {
        $this->seedPage('cloud', 'Kiption Cloud', '');
        $res = $this->client()->get('/cloud');
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame('noindex', $this->header($res, 'X-Robots-Tag'), 'PageController parity: the header, not just the meta');
        $this->assertStringContainsString('noindex', $res->body);
    }

    public function test_static_layer_builds_the_top_level_spelling_only(): void
    {
        $this->seedPage('compare-substack', 'Kiption vs Substack', 'Body.');
        $config = [
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->cacheDir . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->uploads],
        ];
        Builder::build($config, $this->cacheDir);
        $cache = new Cache($this->cacheDir);
        // The 301 must stay authoritative at the static layer, which answers
        // before routing: no file may exist under the /page/view spelling.
        $this->assertFileDoesNotExist($this->cacheDir . '/page/view/compare-substack/index.html');
        $this->assertNull($cache->serve(new Request('GET', '/page/view/compare-substack', [], [], [])));
        $this->assertFileDoesNotExist($this->cacheDir . '/page/view/dmca/index.html');
        $this->assertNull($cache->serve(new Request('GET', '/page/view/dmca', [], [], [])));
        // The top-level spelling builds, and unmapped slugs keep theirs.
        $this->assertNotNull($cache->serve(new Request('GET', '/compare/substack', [], [], [])), 'mapped slug builds at its top-level path');
        $this->assertNotNull($cache->serve(new Request('GET', '/dmca', [], [], [])), 'the migration-seeded dmca row builds at /dmca');
        $this->assertNotNull($cache->serve(new Request('GET', '/page/view/about', [], [], [])), 'an unmapped page slug still builds at /page/view');
        // The compare index rides the build's fixed set (the /news idiom):
        // it exists regardless of page rows, so it must not wait for a
        // first live request to fill.
        $this->assertNotNull($cache->serve(new Request('GET', '/compare', [], [], [])), 'the compare index builds');
    }
}
