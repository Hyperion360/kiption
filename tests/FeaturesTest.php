<?php // tests/FeaturesTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase
{
    private string $path = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-flags-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, dirname(__DIR__) . '/app/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        // Finding 2: Features::init is global state. reset() nulls the memo and
        // drops the DB handle so no later suite in this single phpunit process
        // (alphabetical file order puts this class before the unedited ones)
        // inherits a memo pointing at this unlinking temp DB.
        \App\Features::reset();
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7)); // the bare tempnam stub under the .sqlite suffix
    }

    private function db(): Database
    {
        return $this->db;
    }

    public function test_uninit_resolves_from_the_inventory_alone(): void
    {
        // Finding 1 (load-bearing): before any init(), on() answers from the
        // inventory const alone, all true, no DB handle. Every legacy suite
        // builds App without init-ing Features; a skipped init in production
        // degrades to current behavior instead of bricking the archive.
        \App\Features::reset();
        foreach (array_keys(\App\Features::INVENTORY) as $key) {
            $this->assertTrue(\App\Features::on($key), "uninit {$key} must default on");
        }
        $this->assertFalse(\App\Features::on('no_such_flag'), 'unknown keys fail closed even uninit');
    }

    public function test_defaults_overrides_and_unknown_keys(): void
    {
        \App\Features::init($this->db(), []);
        $this->assertTrue(\App\Features::on('news'), 'inventory default: on');
        $this->assertTrue(\App\Features::on('contact'));
        $this->assertFalse(\App\Features::on('no_such_flag'), 'unknown keys are OFF (fail closed)');
        // a DB row overrides the default
        $this->db()->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['news']);
        \App\Features::init($this->db(), []); // re-init re-reads
        $this->assertFalse(\App\Features::on('news'), 'DB row disables');
        // config defaults flow through
        \App\Features::init($this->db(), ['contact' => false]);
        $this->assertFalse(\App\Features::on('contact'), 'config default off');
        \App\Features::toggle('news', true); // void; the row now reads on
        $this->assertTrue(\App\Features::on('news'));
    }

    public function test_toggle_persists_invalidates_the_memo_and_validates(): void
    {
        \App\Features::init($this->db(), []);
        $this->assertTrue(\App\Features::on('news')); // builds the memo
        \App\Features::toggle('news', false);         // INSERT OR REPLACE + memo drop
        $this->assertFalse(\App\Features::on('news'), 'memo invalidated, DB re-read');
        $row = $this->db()->one('SELECT enabled FROM feature_flags WHERE key = ?', ['news']);
        $this->assertNotNull($row, 'toggle wrote the row');
        $this->assertSame(0, (int) $row['enabled']);
        \App\Features::toggle('news', true);
        $this->assertTrue(\App\Features::on('news'), 'toggled back on');
        $this->expectException(\InvalidArgumentException::class);
        \App\Features::toggle('no_such_flag', true); // unknown key: never an insert
    }

    public function test_all_lists_every_inventory_key(): void
    {
        \App\Features::init($this->db(), ['search' => false]);
        $all = \App\Features::all();
        $this->assertSame(array_keys(\App\Features::INVENTORY), array_keys($all));
        foreach ($all as $key => $entry) {
            $this->assertSame($key === 'search' ? false : true, $entry['on']);
            $this->assertSame('features.' . $key . '.desc', $entry['desc'], 'desc carries the lang key');
        }
    }

    public function test_reset_returns_to_the_inventory_state(): void
    {
        \App\Features::init($this->db(), ['search' => false]);
        $this->assertFalse(\App\Features::on('search'), 'config default off before reset');
        \App\Features::reset();
        $this->assertTrue(\App\Features::on('search'), 'inventory default after reset');
        // The DB handle is dropped too: a row landing afterwards cannot be read.
        $this->db()->query('INSERT INTO feature_flags (key, enabled) VALUES (?, 0)', ['news']);
        $this->assertTrue(\App\Features::on('news'), 'no DB handle: inventory alone');
    }
}
