<?php // tests/SchemaTemplateTest.php
use App\Tests\Support\AppLayout;
use App\Tests\Support\SchemaTemplate;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/** The copied schema template must be what the real Migrator and Seeder make.
 *  If this fails, tests that start from the template are no longer testing the
 *  real schema, so fix the template before trusting any other result. */
#[Medium]
final class SchemaTemplateTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            foreach ([$f, $f . '-wal', $f . '-shm'] as $p) @unlink($p);
        }
    }

    private function track(string $path): string
    {
        $this->files[] = $path;
        $this->files[] = substr($path, 0, -strlen('.sqlite'));
        return $path;
    }

    private function fresh(bool $seed): Database
    {
        $path = $this->track(tempnam(sys_get_temp_dir(), 'tpl-fresh-') . '.sqlite');
        $db = new Database('sqlite:' . $path);
        (new Migrator($db, AppLayout::migrations()))->migrate();
        if ($seed) \App\Seeder::run($db);
        return $db;
    }

    /** @return array<string,string> object name => its CREATE statement */
    private function schema(Database $db): array
    {
        $out = [];
        foreach ($db->all("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name") as $r) {
            $out[$r['type'] . ' ' . $r['name']] = (string) $r['sql'];
        }
        return $out;
    }

    /** @return array<string,int> table => row count */
    private function counts(Database $db): array
    {
        $out = [];
        foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql NOT LIKE 'CREATE VIRTUAL%' ORDER BY name") as $t) {
            $name = (string) $t['name'];
            if (preg_match('/_(data|idx|content|docsize|config)$/', $name) && str_contains($name, '_fts')) continue; // FTS shadow tables
            $out[$name] = (int) $db->one('SELECT COUNT(*) c FROM "' . $name . '"')['c'];
        }
        return $out;
    }

    public function test_the_migrated_template_has_the_schema_and_ledger_of_a_real_migration(): void
    {
        $template = new Database('sqlite:' . $this->track(SchemaTemplate::path('tpl-check-')));
        $real = $this->fresh(false);

        self::assertSame($this->schema($real), $this->schema($template));
        self::assertSame(
            array_column($real->all('SELECT name, batch FROM _migrations ORDER BY name'), 'batch', 'name'),
            array_column($template->all('SELECT name, batch FROM _migrations ORDER BY name'), 'batch', 'name'),
        );
        self::assertSame($this->counts($real), $this->counts($template));
    }

    public function test_the_seeded_template_has_the_rows_of_a_real_seed(): void
    {
        $template = new Database('sqlite:' . $this->track(SchemaTemplate::seededPath('tpl-seed-')));
        $real = $this->fresh(true);

        self::assertSame($this->schema($real), $this->schema($template));
        self::assertSame($this->counts($real), $this->counts($template));
        // The seed is real data: the same people exist in both, only hash bytes and clocks may differ.
        self::assertSame(
            array_column($real->all('SELECT email, role FROM users ORDER BY email'), 'role', 'email'),
            array_column($template->all('SELECT email, role FROM users ORDER BY email'), 'role', 'email'),
        );
    }

    public function test_every_copy_is_its_own_file(): void
    {
        $a = $this->track(SchemaTemplate::path('tpl-own-'));
        $b = $this->track(SchemaTemplate::path('tpl-own-'));
        self::assertNotSame($a, $b);
        (new Database('sqlite:' . $a))->query('CREATE TABLE only_in_a (x INTEGER)');
        $names = array_column((new Database('sqlite:' . $b))->all("SELECT name FROM sqlite_master WHERE name = 'only_in_a'"), 'name');
        self::assertSame([], $names, 'a change to one copy never reaches another or the template');
    }
}
