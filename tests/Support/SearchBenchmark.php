<?php
declare(strict_types=1);
namespace App\Tests\Support;

use Kip\Database;

/** Safety and measurement helpers for the disposable synthetic benchmark. */
final class SearchBenchmark
{
    public static function options(array $args): array
    {
        $options = ['stories' => 10000, 'iterations' => 100, 'json' => sys_get_temp_dir() . '/kiption-search-benchmark.json'];
        $seen = [];
        foreach ($args as $arg) {
            if (!preg_match('/^--(stories|iterations|json)=(.+)$/D', $arg, $match) || isset($seen[$match[1]])) {
                throw new \InvalidArgumentException('Use --stories=N --iterations=N --json=PATH once each; no database options.');
            }
            $seen[$match[1]] = true;
            if ($match[1] !== 'json' && !ctype_digit($match[2])) throw new \InvalidArgumentException('Counts must be positive integers.');
            $options[$match[1]] = $match[1] === 'json' ? $match[2] : (int) $match[2];
        }
        if ($options['stories'] < 100 || $options['stories'] > 100000 || $options['iterations'] < 10 || $options['iterations'] > 1000) {
            throw new \InvalidArgumentException('Stories must be 100..100000; iterations must be 10..1000.');
        }
        $path = $options['json'];
        if (str_contains($path, '://') || str_contains($path, "\0") || !str_ends_with($path, '.json')
            || !is_dir(dirname($path)) || !is_writable(dirname($path)) || file_exists($path) || is_link($path)) {
            throw new \InvalidArgumentException('Output must be a new .json file in an existing writable local directory.');
        }
        return $options;
    }

    /** Median and nearest-rank p95 of measured milliseconds. */
    public static function stats(array $samples): array
    {
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $middle = intdiv($n, 2);
        $median = $n % 2 === 0 ? ($samples[$middle - 1] + $samples[$middle]) / 2 : $samples[$middle];
        return ['median_ms' => $median,
            'p95_ms' => $samples[(int) ceil($n * .95) - 1], 'max_ms' => $samples[$n - 1]];
    }

    /** The alias p also names materialized spell_matches; only spell_best reads postings. */
    public static function postingsScans(array $plan): array
    {
        $nodes = array_column($plan, null, 'id');
        $scans = [];
        foreach ($plan as $row) {
            if (!preg_match('/\bSCAN (?:p|search_word_posts)\b/', $row['detail'])) continue;
            $parent = $nodes[$row['parent']] ?? null;
            while ($parent !== null && !str_contains($parent['detail'], 'spell_best')) {
                $parent = $nodes[$parent['parent']] ?? null;
            }
            if ($parent !== null || str_contains($row['detail'], 'search_word_posts')) $scans[] = $row['detail'];
        }
        return $scans;
    }

    /** Capture real execute parameters without reopening the database or changing production APIs.
     *  The statement subclass exists only during this untimed call; timed calls use native PDO. */
    public static function capture(Database $db, callable $run): array
    {
        $pdo = (new \ReflectionProperty(Database::class, 'pdo'))->getValue($db);
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $statements = [];
        $record = static function (string $sql, array $params) use (&$statements): void { $statements[] = [$sql, $params]; };
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [SearchBenchmarkStatement::class, [$record]]);
        try { $run(); }
        finally { $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, $previous); }
        return $statements;
    }
}

final class SearchBenchmarkStatement extends \PDOStatement
{
    protected function __construct(private \Closure $record) {}

    public function execute(?array $params = null): bool
    {
        ($this->record)($this->queryString, $params ?? []);
        return parent::execute($params);
    }
}
