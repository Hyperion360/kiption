<?php // tests/LookLayoutTest.php
namespace App\Tests;

use App\Look\{Boot, Compiler, Presets, Store};
use Kip\{App, Database};
use Kip\Http\{Request, Response};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The saved look reaches the page: the stylesheet link sits after the base
 *  and skin sheets (and is absent by default), the public route answers with
 *  the cache policy the front controller keeps, and a saved look adds no
 *  query to a page. */
final class LookLayoutTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-looklay-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-looklay-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        symlink(dirname(__DIR__) . '/app/skins', $this->root . '/app/skins');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        Boot::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function config(string $skin = 'default'): array
    {
        $appDir = dirname(__DIR__) . '/app';
        return [
            'env' => 'prod',
            'views' => $appDir . '/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example', 'items_per_page' => 20,
            'skin' => $skin,
            'nav_file' => $appDir . '/nav.json',
            'app_dir' => $this->root . '/app',
            'features_dir' => $appDir . '/Features',
            'public_dir' => $this->root . '/public',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
            'site_look_dir' => $this->root . '/site-look',
        ];
    }

    /** The boot order of public/index.php: features, settings, skins, look. */
    private function app(string $skin = 'default'): App
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $config = \App\Settings::apply($this->config($skin));
        $config['views_override'] = \App\Skins::overrideDir($config);
        Boot::init();
        return new App($config);
    }

    private function saveLook(array $over = []): array
    {
        $values = $over + ['accent' => '#8a2be2', 'bg' => '#ffffff', 'ink' => '#111111', 'width' => 'wide', 'size' => 'standard', 'density' => 'standard', 'header' => 'standard'];
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Store::put($values);
        return $values;
    }

    public function test_no_link_until_a_look_is_saved(): void
    {
        foreach (['default', 'classic', 'manuscript'] as $skin) {
            $body = (new TestClient($this->app($skin)))->get('/browse')->body;
            $this->assertStringNotContainsString('/look/css', $body, $skin);
        }
    }

    public function test_the_link_follows_the_base_and_skin_sheets_with_a_revision(): void
    {
        $values = $this->saveLook();
        $rev = Compiler::revision($values);
        foreach (['default' => null, 'classic' => '/assets/skins/classic.css', 'manuscript' => '/assets/skins/manuscript.css'] as $skin => $sheet) {
            Boot::reset();
            $body = (new TestClient($this->app($skin)))->get('/browse')->body;
            $link = strpos($body, '<link rel="stylesheet" href="/look/css?v=' . $rev . '">');
            $this->assertNotFalse($link, $skin . ' carries the link');
            $this->assertGreaterThan(strpos($body, '/assets/reader.css'), $link, $skin);
            if ($sheet !== null) $this->assertGreaterThan(strpos($body, $sheet), $link, $skin . ' loads after its skin sheet');
        }
    }

    public function test_the_folder_comes_from_config_or_falls_back_under_the_data_directory(): void
    {
        $this->assertSame('/x/look', Boot::dir(['site_look_dir' => '/x/look']));
        $this->assertSame('/x/data/site-look', Boot::dir(['data_dir' => '/x/data/']));
        $this->assertStringEndsWith('/app/site-look', Boot::dir([]));
    }

    public function test_the_route_answers_404_then_200_with_the_cache_policy(): void
    {
        $client = new TestClient($this->app());
        $this->assertSame(404, $client->get('/look/css')->status);
        $values = $this->saveLook();
        $rev = Compiler::revision($values);
        $client = new TestClient($this->app());
        $fresh = $client->get('/look/css', ['v' => $rev]);
        $this->assertSame(200, $fresh->status);
        $this->assertSame('public, max-age=31536000, immutable', $fresh->headers['Cache-Control']);
        $this->assertSame('public, max-age=300', $client->get('/look/css', ['v' => 'nope'])->headers['Cache-Control']);
    }

    public function test_the_front_controller_stamp_keeps_only_the_public_look_shape(): void
    {
        $get = new Request('GET', '/look/css', [], [], []);
        $ok = new Response('a{}', 200, ['Content-Type' => 'text/css', 'Cache-Control' => 'public, max-age=31536000, immutable']);
        $this->assertSame('public, max-age=31536000, immutable', \App\StaticCache\Cache::stamp(null, $get, $ok)->headers['Cache-Control']);
        $short = new Response('a{}', 200, ['Cache-Control' => 'public, max-age=300']);
        $this->assertSame('public, max-age=300', \App\StaticCache\Cache::stamp(null, $get, $short)->headers['Cache-Control']);
        $no = 'private, no-store';
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, new Request('POST', '/look/css', [], [], []), $ok)->headers['Cache-Control'], 'a POST');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, $get, new Response('x', 404, ['Cache-Control' => 'public, max-age=300']))->headers['Cache-Control'], 'a 404');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, $get, $ok->withHeader('Set-Cookie', 'a=b'))->headers['Cache-Control'], 'a cookie');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, new Request('GET', '/look/css/x', [], [], []), $ok)->headers['Cache-Control'], 'another path');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, new Request('GET', '/look', [], [], []), $ok)->headers['Cache-Control'], 'the admin screen');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, $get, new Response('a{}', 200, ['Cache-Control' => 'public, max-age=99999999999']))->headers['Cache-Control'], 'a lifetime outside the shape');
        $this->assertSame($no, \App\StaticCache\Cache::stamp(null, $get, new Response('a{}', 200))->headers['Cache-Control'], 'no header at all');
    }

    public function test_no_part_of_the_request_picks_the_file(): void
    {
        $this->saveLook();
        $client = new TestClient($this->app());
        foreach (['../../config', '..%2f..%2fconfig', 'look.css', "\0"] as $v) {
            $r = $client->get('/look/css', ['v' => $v]);
            $this->assertSame(200, $r->status);
            $this->assertStringStartsWith('/* look ', $r->body);
        }
        $this->assertSame(404, $client->get('/look/css/../../config.php')->status);
    }

    public function test_a_saved_look_adds_no_query_to_a_page(): void
    {
        $count = function (): int {
            array_map('unlink', glob($this->root . '/app/cache.sqlite*') ?: []); // the page cache would answer the second run with no query
            $app = $this->app();
            $db = $app->container->make(Database::class);
            $n = 0;
            \App\Tests\Support\QueryTap::on($db, function () use (&$n): void { $n++; });
            $res = $app->handle(new Request('GET', '/browse', [], [], []));
            \App\Tests\Support\QueryTap::off($db);
            $this->assertSame(200, $res->status);
            return $n;
        };
        $without = $count();
        Boot::reset();
        $this->saveLook();
        $this->assertSame($without, $count());
    }

    public function test_every_preset_has_a_matching_preview_rule(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        foreach (Presets::GROUPS as $group => $names) {
            foreach (array_keys($names) as $name) {
                if ($name === 'standard') continue;
                $this->assertStringContainsString('.look-preview[data-' . $group . '="' . $name . '"]', $css, "$group=$name has no preview rule");
            }
        }
        // The numbers the previews use match the presets they stand for. The
        // panes carry --look-* names, so they never rebind the real --read-* tokens.
        $this->assertStringContainsString('--look-width: 560px', $css);
        $this->assertStringContainsString('--look-size: 21px', $css);
        $this->assertStringContainsString('--look-lh: 1.85', $css);
    }

    public function test_the_button_tokens_exist_and_the_hard_coded_green_is_gone_from_buttons(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/reader.css');
        $this->assertMatchesRegularExpression('/:root\{[^}]*--btn:#1f4f42; --btn-ink:#fff;/', $css);
        foreach (['.continue-cta', '.cta-primary'] as $selector) {
            $i = strpos($css, $selector . ' {');
            $this->assertNotFalse($i, $selector);
            $block = substr($css, $i, strpos($css, '}', $i) - $i);
            $this->assertStringContainsString('var(--btn)', $block, $selector);
            $this->assertStringNotContainsString('#1f4f42', $block, $selector);
        }
    }
}
