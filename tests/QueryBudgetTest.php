<?php // tests/QueryBudgetTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class QueryBudgetTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-budget-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, dirname(__DIR__) . '/app/migrations'))->migrate();
        \App\Seeder::run($db);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @dataProvider pages */
    public function test_every_page_stays_inside_the_one_query_budget(string $page): void
    {
        $app = new App([
            'env' => 'prod',
            'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
        ]);
        $db = $app->container->make(Database::class);
        $queries = 0;
        $db->onQuery(function () use (&$queries): void { $queries++; });
        $res = $app->handle(new Request('GET', $page, [], [], []));
        $db->onQuery(fn () => null);
        $this->assertSame(200, $res->status, $page);
        $this->assertLessThanOrEqual(1, $queries, "{$page} ran {$queries} content queries, budget is 1");
    }

    public static function pages(): array
    {
        return [['/'], ['/browse'], ['/browse/recent'], ['/browse/category/general'],
                ['/story/view/the-rabbit-hole'], ['/story/read/the-rabbit-hole/1'], ['/story/read/the-rabbit-hole/3']];
    }
}
