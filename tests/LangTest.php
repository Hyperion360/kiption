<?php // tests/LangTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-lang-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->root = sys_get_temp_dir() . '/kiption-lang-pack-' . uniqid();
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        // Finding 16: reset the global current lang so a failed assertion cannot
        // leak the test pack into later classes in this process.
        \App\Lang::setCurrent('en');
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        array_map('unlink', glob($this->root . '/*.php') ?: []);
        @rmdir($this->root);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function config(): array
    {
        return [
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-lang-test.log', 'from' => 'noreply@kiption.test'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-lang-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ];
    }

    private function client(): TestClient
    {
        return new TestClient(new App($this->config()));
    }

    public function test_lang_fallback_and_interpolation(): void
    {
        \App\Lang::setCurrent('en');
        $this->assertSame('Log in', \App\Lang::t('nav.login'));
        $this->assertSame('Chapter 3 of 7', \App\Lang::t('story.chapter_of', ['n' => '3', 'm' => '7']));
        $this->assertSame('story.nonexistent.key', \App\Lang::t('story.nonexistent.key'), 'missing keys are visible, not fatal');
        // A test pack overrides with en fallback underneath. FLAT dotted keys
        // (finding 1): the pack is one [key => text] array, merged over en.
        file_put_contents($this->root . '/xx.php', '<?php return ["nav.login" => "Ensalida"];');
        \App\Lang::addPackPath('xx', $this->root . '/xx.php');
        \App\Lang::setCurrent('xx');
        $this->assertSame('Ensalida', \App\Lang::t('nav.login'));
        $this->assertSame('Browse', \App\Lang::t('nav.browse'), 'en fallback');
        $this->assertSame('Chapter 3 of 7', \App\Lang::t('story.chapter_of', ['n' => '3', 'm' => '7']), 'params interpolate under a pack too');
    }

    public function test_invalid_lang_code_falls_back_to_en(): void
    {
        // The missing-key philosophy: bad input is soft, the site stays up.
        \App\Lang::setCurrent('4x!EN');
        $this->assertSame('Log in', \App\Lang::t('nav.login'), 'junk coerces to the en pack');
        \App\Lang::setCurrent('en');
    }

    public function test_corrupt_pack_falls_back_to_en(): void
    {
        file_put_contents($this->root . '/bad.php', '<?php return ["nav.login" => "Ensalida"'); // parse error
        \App\Lang::addPackPath('zz', $this->root . '/bad.php');
        \App\Lang::setCurrent('zz');
        $this->assertSame('Log in', \App\Lang::t('nav.login'), 'a broken pack serves en, never a fatal');
        // A pack that loads but is not a [key => text] map reads as empty too.
        file_put_contents($this->root . '/str.php', '<?php return "not an array";');
        \App\Lang::addPackPath('st', $this->root . '/str.php');
        \App\Lang::setCurrent('st');
        $this->assertSame('Log in', \App\Lang::t('nav.login'));
        // With no registration, autodiscovery looks for app/lang/qq.php; the
        // missing file is the same empty-pack case (en underneath).
        \App\Lang::setCurrent('qq');
        $this->assertSame('Log in', \App\Lang::t('nav.login'));
        \App\Lang::setCurrent('en');
    }

    public function test_views_render_translated_strings(): void
    {
        file_put_contents($this->root . '/xx.php', '<?php return ["nav.login" => "Ensalida"];');
        \App\Lang::addPackPath('xx', $this->root . '/xx.php'); // nav.login => Ensalida
        \App\Lang::setCurrent('xx');
        $body = $this->client()->get('/auth/login')->body;
        $this->assertStringContainsString('Ensalida', $body, 'the nav brand string renders from the pack');
        \App\Lang::setCurrent('en');
        $this->assertStringContainsString('Log in', $this->client()->get('/auth/login')->body);
    }

    public function test_html_lang_attribute_follows_the_pack(): void
    {
        // Under any non-en pack the document itself must stop claiming to be
        // English: screen readers pick their voice from <html lang>.
        file_put_contents($this->root . '/xx.php', '<?php return ["nav.login" => "Ensalida"];');
        \App\Lang::addPackPath('xx', $this->root . '/xx.php');
        \App\Lang::setCurrent('xx');
        $this->assertSame('xx', \App\Lang::current());
        $this->assertStringContainsString('<html lang="xx">', $this->client()->get('/auth/login')->body);
        \App\Lang::setCurrent('en');
        $this->assertStringContainsString('<html lang="en">', $this->client()->get('/auth/login')->body);
    }

    public function test_every_view_string_is_extracted(): void
    {
        // The completeness contract: no hardcoded English UI literals remain in
        // text nodes outside the allowlist. The allowlist is brand + tech tokens
        // plus the code fragments the regex catches once attribute values move
        // into t() calls (" content=", '" href="', '" alt="', '?page=').
        $allowlist = ['Kiption', 'PHP', 'UTF-8', 'aria-', 'data-', 'http', 'page=', 'content=', 'href=', 'alt=', 'placeholder='];
        $files = array_merge(
            glob(dirname(__DIR__) . '/app/views/*.php'),
            glob(dirname(__DIR__) . '/app/views/*/*.php'),
            glob(dirname(__DIR__) . '/app/Features/*/views/*.php'),
            glob(dirname(__DIR__) . '/app/Features/*/views/*/*.php')
        );
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            foreach (preg_split('/\n/', (string) file_get_contents($f)) as $line) {
                if (preg_match('/>([^<>{}]*[A-Za-z]{3}[^<>{}]*)</', $line, $m)) {
                    $text = trim(strip_tags('<x>' . $m[1] . '</x>'));
                    if ($text === '' || preg_match('/^[^a-zA-Z]*$/', $text)) continue;
                    foreach ($allowlist as $a) { if (str_contains($text, $a)) continue 2; }
                    $this->fail(basename($f) . ' still hardcodes: ' . $text);
                }
            }
        }
        $this->assertTrue(true);
    }
}
