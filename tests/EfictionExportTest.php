<?php // tests/EfictionExportTest.php
namespace App\Tests;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/EfictionInstall.php';

final class EfictionExportTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kiption-efi-' . uniqid('', true);
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function install(): \EfictionInstall
    {
        return new \EfictionInstall($this->root);
    }

    public function test_fixture_builds_schema_settings_and_files(): void
    {
        $fx = $this->install();
        $this->assertSame(2, $fx->count('fanfiction_authors'));
        $this->assertSame(1, (int) $fx->one('SELECT validated FROM ' . $fx->prefix . 'fanfiction_authorprefs WHERE uid = 1')['validated']);
        $this->assertFileExists($this->root . '/stories/1/10.txt');
        $this->assertSame('files', $fx->settings()['store'] === 'files' ? 'files' : 'db', 'settings row present');
    }

    public function test_db_shims_expose_the_efiction_globals(): void
    {
        $fx = $this->install();
        $fx->installShims();
        $this->assertTrue(function_exists('dbquery'));
        $q = dbquery('SELECT penname FROM ' . TABLEPREFIX . 'fanfiction_authors WHERE uid = 1');
        $row = dbassoc($q);
        $this->assertSame('Legacy Author', $row['penname']);
    }
}
