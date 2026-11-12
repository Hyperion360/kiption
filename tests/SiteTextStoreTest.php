<?php // tests/SiteTextStoreTest.php
namespace App\Tests;

use App\Lang;
use App\SiteText\Compiler;
use App\SiteText\Store;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class SiteTextStoreTest extends TestCase
{
    private string $path = '';
    private string $dir = '';
    private Database $db;
    private Store $store;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-st-') . '.sqlite';
        $this->dir = sys_get_temp_dir() . '/kiption-st-' . bin2hex(random_bytes(4));
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->store = new Store($this->db);
        Lang::reset();
        Lang::useSiteText($this->dir);
    }

    protected function tearDown(): void
    {
        Lang::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    public function test_put_saves_and_an_empty_value_deletes(): void
    {
        $this->assertNull($this->store->put('en', 'nav.login', 'Sign in'));
        $this->assertSame(['nav.login' => 'Sign in'], $this->store->rows('en'));
        $this->assertNull($this->store->put('en', 'nav.login', ''));
        $this->assertSame([], $this->store->rows('en'));
    }

    public function test_a_value_equal_to_the_built_in_text_deletes_the_row(): void
    {
        $this->store->put('en', 'nav.login', 'Sign in');
        $this->assertNull($this->store->put('en', 'nav.login', 'Log in'));
        $this->assertSame([], $this->store->rows('en'));
    }

    public function test_put_refuses_unsafe_text_unknown_keys_and_unknown_languages(): void
    {
        $this->assertNotNull($this->store->put('en', 'nav.login', '<b>x</b>'));
        $this->assertNotNull($this->store->put('en', 'no.such.key', 'x'));
        $this->assertNotNull($this->store->put('zz', 'nav.login', 'x'));
        $this->assertSame([], $this->store->rows('en'));
    }

    public function test_a_carriage_return_never_gets_stored(): void
    {
        $this->assertNotNull($this->store->put('en', 'nav.login', "Sign\r\nin"), 'no line break allowed in this string');
        $this->assertNotNull($this->store->put('en', 'nav.login', "Sign\rin"));
        $this->assertSame([], $this->store->rows('en'));
    }

    public function test_languages_add_and_remove(): void
    {
        $this->assertNull($this->store->addLanguage('fr', 'Francais', 'ltr'));
        $this->assertNotNull($this->store->addLanguage('fr', 'Again', 'ltr'), 'duplicate');
        $this->assertNotNull($this->store->addLanguage('F1', 'Bad', 'ltr'), 'bad code');
        $this->assertNotNull($this->store->addLanguage('de', 'A<b>', 'ltr'), 'bad name');
        $this->assertNotNull($this->store->addLanguage('de', 'Deutsch', 'up'), 'bad dir');
        $this->assertNotNull($this->store->addLanguage('de', str_repeat('a', 41), 'ltr'), 'long name');
        $this->store->put('fr', 'nav.login', 'Connexion');
        $this->assertNotNull($this->store->removeLanguage('en'), 'built-in cannot go');
        $this->assertNull($this->store->removeLanguage('fr'));
        $this->assertSame([], $this->store->rows('fr'));
    }

    public function test_import_is_all_or_nothing(): void
    {
        $r = $this->store->import('en', ['nav.login' => 'Sign in', 'nav.browse' => '<x>']);
        $this->assertSame(0, $r['saved']);
        $this->assertNotNull($r['error']);
        $this->assertSame([], $this->store->rows('en'));
        $r = $this->store->import('en', ['nav.login' => 'Sign in', 'nav.browse' => 'Look']);
        $this->assertSame(2, $r['saved']);
        $this->assertCount(2, $this->store->rows('en'));
    }

    public function test_compile_makes_lang_t_return_the_override(): void
    {
        $this->store->put('en', 'nav.login', 'Sign in');
        $this->assertTrue(Compiler::compile($this->db, $this->dir));
        Lang::setCurrent('en');
        $this->assertSame('Sign in', Lang::t('nav.login'));
        $this->assertSame('Browse', Lang::t('nav.browse'));
    }

    public function test_the_compiled_file_is_a_valid_php_array(): void
    {
        $this->store->put('en', 'nav.login', "It's \\ fine");
        Compiler::compile($this->db, $this->dir);
        $map = require $this->dir . '/en.php';
        $this->assertSame("It's \\ fine", $map['nav.login']);
        $this->assertIsArray(require $this->dir . '/index.php');
    }

    public function test_the_compiler_skips_a_row_inserted_around_the_rules(): void
    {
        $this->db->query("INSERT INTO site_text (lang, key, value) VALUES ('en', 'nav.login', '<script>alert(1)</script>')");
        $this->db->query("INSERT INTO site_text (lang, key, value) VALUES ('en', 'nav.browse', 'Look')");
        $this->db->query("INSERT INTO site_text (lang, key, value) VALUES ('en', '_dir', 'rtl')");
        $this->db->query("INSERT INTO site_text (lang, key, value) VALUES ('zz', 'nav.login', 'x')");
        Compiler::compile($this->db, $this->dir);
        $file = (string) file_get_contents($this->dir . '/en.php');
        $this->assertStringNotContainsString('script', $file);
        $this->assertStringNotContainsString('_dir', $file);
        $this->assertStringContainsString('Look', $file);
        $this->assertFileDoesNotExist($this->dir . '/zz.php', 'a language nobody installed gets no file');
    }

    public function test_installed_includes_an_added_language_and_removal_removes_its_file(): void
    {
        $this->store->addLanguage('fr', 'Francais', 'ltr');
        Compiler::compile($this->db, $this->dir);
        $this->assertContains('fr', Lang::installed());
        $this->assertSame('Francais', Lang::name('fr'));
        $this->assertFileExists($this->dir . '/fr.php');
        $this->store->removeLanguage('fr');
        Compiler::compile($this->db, $this->dir);
        $this->assertFileDoesNotExist($this->dir . '/fr.php');
        $this->assertNotContains('fr', Lang::installed());
    }

    public function test_dir_reads_an_rtl_owner_language_and_untranslated_keys_fall_back(): void
    {
        $this->store->addLanguage('ar', 'Arabic', 'rtl');
        $this->store->put('ar', 'nav.login', 'Dukhul');
        Compiler::compile($this->db, $this->dir);
        Lang::setCurrent('ar');
        $this->assertSame('rtl', Lang::dir());
        $this->assertSame('Dukhul', Lang::t('nav.login'));
        $this->assertSame('Browse', Lang::t('nav.browse'), 'English underneath');
    }

    public function test_a_missing_folder_is_created_and_a_failure_is_not_fatal(): void
    {
        $this->assertTrue(Compiler::compile($this->db, $this->dir . '/deep/er'));
        file_put_contents($this->dir . '/blocker', 'x');
        $this->assertFalse(@Compiler::compile($this->db, $this->dir . '/blocker/sub'));
        Lang::setCurrent('en');
        $this->assertSame('Log in', Lang::t('nav.login'));
    }
}
