<?php // tests/SiteTextBootTest.php
namespace App\Tests;

use App\Lang;
use App\SiteText\Boot;
use App\SiteText\Store;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SiteTextBootTest extends TestCase
{
    private string $path = '';
    private string $dir = '';
    private Database $db;
    private array $config;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-boot-') . '.sqlite';
        $this->dir = sys_get_temp_dir() . '/kiption-boot-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->config = ['site_text_dir' => $this->dir, 'ui_lang' => 'en'];
        Lang::reset();
    }

    protected function tearDown(): void
    {
        Lang::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_boot_uses_the_saved_default_language_unless_the_cookie_chose(): void
    {
        $store = new Store($this->db);
        $store->addLanguage('fr', 'Francais', 'ltr');
        $store->addLanguage('de', 'Deutsch', 'ltr');
        $store->put('fr', 'nav.login', 'Connexion');
        $store->put('de', 'nav.login', 'Anmelden');

        $this->config['ui_lang'] = 'fr';
        Boot::early($this->config);
        Boot::late($this->config, $this->db);
        $this->assertSame('fr', Lang::current(), 'the saved default applies');
        $this->assertSame('Connexion', Lang::t('nav.login'), 'and the folder was compiled on first use');

        Boot::late($this->config, $this->db, 'de', true);
        $this->assertSame('de', Lang::current(), 'a member cookie wins when allowed');

        Boot::late($this->config, $this->db, 'de', false);
        $this->assertSame('fr', Lang::current(), 'a cookie is ignored when per-member language is off');

        Boot::late($this->config, $this->db, 'qq', true);
        $this->assertSame('fr', Lang::current(), 'a cookie for a language nobody installed is ignored');
    }

    public function test_a_failed_compile_backs_off_instead_of_retrying_every_request(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/site-text', 'x'); // a file where the folder should go, so compile fails
        $this->config['site_text_dir'] = $this->dir . '/site-text';
        $marker = $this->dir . '/site-text.failed';
        (new Store($this->db))->addLanguage('fr', 'Francais', 'ltr');

        Boot::early($this->config);
        Boot::late($this->config, $this->db);
        $this->assertFileExists($marker, 'a failed compile leaves a back-off marker');

        $past = time() - 100;
        touch($marker, $past);
        Boot::late($this->config, $this->db);
        $this->assertSame($past, filemtime($marker), 'within five minutes compile is not attempted again');

        touch($marker, time() - 400);
        Boot::late($this->config, $this->db);
        $this->assertGreaterThan(time() - 50, filemtime($marker), 'after five minutes it tries again and re-marks');
    }

    public function test_an_uninstalled_default_falls_back_to_english_and_an_unmigrated_db_is_not_fatal(): void
    {
        $this->config['ui_lang'] = 'zz';
        Boot::early($this->config);
        $this->assertSame('en', Lang::current());

        $bare = new Database('sqlite:' . $this->path . '-bare');
        Boot::late($this->config, $bare);
        $this->assertSame('en', Lang::current());
        @unlink($this->path . '-bare');
    }

    public function test_the_front_controller_and_cli_call_late_after_the_settings_overlay(): void
    {
        foreach (['public/index.php', 'bin/kip'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
            $apply = strpos($src, 'Settings::apply($config)');
            $late = strpos($src, 'SiteText\\Boot::late(');
            $this->assertNotFalse($apply, $file);
            $this->assertNotFalse($late, $file);
            $this->assertGreaterThan($apply, $late, $file . ': the saved default exists only after the overlay');
        }
    }
}
