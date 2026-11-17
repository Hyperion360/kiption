<?php
declare(strict_types=1);
namespace App\Tests;

use App\Tests\Support\SearchBenchmark;
use Kip\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchBenchmarkTest extends TestCase
{
    public static function invalidOptions(): iterable
    {
        foreach ([['--dsn=sqlite:/tmp/archive.sqlite'], ['--database=/tmp/archive.sqlite'], ['--stories=99'],
            ['--stories=100001'], ['--iterations=9'], ['--iterations=1001'], ['--stories=1e4'],
            ['--stories=100', '--stories=200'], ['--json=php://stdout'], ['--json=/tmp/archive.sqlite'], ['--help']] as $args) {
            yield implode(' ', $args) => [$args];
        }
    }

    #[DataProvider('invalidOptions')]
    public function test_options_reject_unsafe_or_unbounded_inputs(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SearchBenchmark::options($args);
    }

    public function test_options_accept_only_bounded_counts_and_a_new_json_file(): void
    {
        $path = sys_get_temp_dir() . '/search-report-' . bin2hex(random_bytes(6)) . '.json';
        $this->assertSame(['stories' => 100, 'iterations' => 10, 'json' => $path],
            SearchBenchmark::options(['--stories=100', '--iterations=10', '--json=' . $path]));
        file_put_contents($path, 'keep');
        try {
            $this->expectException(\InvalidArgumentException::class);
            SearchBenchmark::options(['--json=' . $path]);
        } finally { unlink($path); }
    }

    public function test_statement_capture_observes_execute_binds_and_restores_pdo(): void
    {
        $db = new Database('sqlite::memory:');
        $pdo = (new \ReflectionProperty(Database::class, 'pdo'))->getValue($db);
        $before = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $captured = SearchBenchmark::capture($db, fn () => $db->one('SELECT ? AS value, ? AS other', ['bound', 42]));
        $this->assertSame([['SELECT ? AS value, ? AS other', ['bound', 42]]], $captured);
        $this->assertSame($before, $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS));
        try {
            SearchBenchmark::capture($db, static function (): void { throw new \RuntimeException('sentinel'); });
            $this->fail('The callback exception must propagate.');
        } catch (\RuntimeException $error) { $this->assertSame('sentinel', $error->getMessage()); }
        $this->assertSame($before, $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS));
        $this->assertSame('after', $db->one('SELECT ? AS value', ['after'])['value']);
    }

    public function test_postings_scan_detection_distinguishes_materialized_match_rows(): void
    {
        $plan = [
            ['id' => 1, 'parent' => 0, 'detail' => 'CO-ROUTINE spell_best'],
            ['id' => 2, 'parent' => 1, 'detail' => 'SCAN p USING COVERING INDEX idx_search_word_posts_story'],
            ['id' => 3, 'parent' => 0, 'detail' => 'SCAN p'],
            ['id' => 4, 'parent' => 1, 'detail' => 'SEARCH p USING PRIMARY KEY (word=?)'],
        ];
        $this->assertSame([$plan[1]['detail']], SearchBenchmark::postingsScans($plan));
        $this->assertSame([], SearchBenchmark::postingsScans(array_slice($plan, 2)));
    }

    public function test_cli_smoke_cleans_its_database_and_reports_real_measurements(): void
    {
        $dir = sys_get_temp_dir() . '/search-runner-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $report = $dir . '/report.json';
        try {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__ . '/performance/search.php', '--stories=100', '--iterations=10', '--json=' . $report],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $dir]);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $code = proc_close($process);
            $this->assertContains($code, [0, 1], $stdout . $stderr);
            $this->assertSame('', $stderr);
            $result = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(100, $result['stories']);
            $this->assertSame(10, $result['iterations']);
            $this->assertCount(21, $result['queries']);
            foreach ($result['queries'] as $shape) {
                $this->assertSame(1, $shape['statement_min']);
                $this->assertSame(1, $shape['statement_max']);
                $this->assertGreaterThan(0, $shape['median_ms']);
                $this->assertNotEmpty($shape['explain']);
            }
            $this->assertSame(10, $result['dataset']['hidden_stories']);
            $this->assertGreaterThan(0, $result['new_index_bytes']);
            foreach ($result['writes'] as $shape) {
                $this->assertSame(100, $shape['samples']);
                $this->assertTrue($shape['rolled_back']);
            }
            // Latency/planner gates are machine dependent; report exit status must agree with findings.
            $this->assertSame($result['failures'] === [] ? 0 : 1, $code);
            $this->assertSame([$report], glob($dir . '/*'));
        } finally {
            if (is_file($report)) unlink($report);
            rmdir($dir);
        }
    }

    public function test_stats_use_nearest_rank_p95(): void
    {
        $this->assertSame(['median_ms' => 10.5, 'p95_ms' => 19.0, 'max_ms' => 20.0],
            SearchBenchmark::stats(array_map('floatval', range(20, 1))));
        $this->assertSame(['median_ms' => 2.0, 'p95_ms' => 3.0, 'max_ms' => 3.0], SearchBenchmark::stats([3.0, 1.0, 2.0]));
    }
}
