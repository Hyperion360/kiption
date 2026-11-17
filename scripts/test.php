<?php // scripts/test.php
declare(strict_types=1);

/**
 * Parallel PHPUnit runner with no extra dependencies.
 *
 *   php scripts/test.php [options] [test-file-or-dir ...]
 *
 *   --jobs=N               worker processes (default: CPU count, at most 8)
 *   --group=NAME           only tests in a size group (small, medium, large)
 *   --exclude-group=NAME   skip a size group, e.g. --exclude-group=large,process
 *   --random[=SEED]        run each worker in random order and print the seed
 *   --stop-on-failure      stop each worker at its first failure
 *
 * With no paths it runs every test file the suite lists (tests/ and each
 * app/Features/<Name>/Tests). Test files are split across workers by the
 * timings of the last run (kept in .phpunit-timings.json, gitignored), or by
 * file size on the first run, then each worker is a plain phpunit process on a
 * generated config. Every test already owns its temp paths, databases and
 * ports, so workers share nothing. The totals come from the workers' JUnit
 * logs; the exit code is non-zero if any worker failed.
 *
 * This is the same suite and the same assertions as `vendor/bin/phpunit`.
 * Run that for a single serial pass, and use this for the push gate.
 */

$root = dirname(__DIR__);
$opts = ['jobs' => min(8, max(2, cpuCount())), 'group' => null, 'exclude' => null, 'random' => null, 'stop' => false];
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--jobs=(\d+)$/', $arg, $m)) $opts['jobs'] = max(1, (int) $m[1]);
    elseif (preg_match('/^--group=([a-z,]+)$/', $arg, $m)) $opts['group'] = $m[1];
    elseif (preg_match('/^--exclude-group=([a-z,]+)$/', $arg, $m)) $opts['exclude'] = $m[1];
    elseif ($arg === '--random') $opts['random'] = (string) random_int(1, 999999);
    elseif (preg_match('/^--random=(\d+)$/', $arg, $m)) $opts['random'] = $m[1];
    elseif ($arg === '--stop-on-failure') $opts['stop'] = true;
    elseif ($arg[0] === '-') { fwrite(STDERR, "unknown option {$arg}\n"); exit(2); }
    else $paths[] = $arg;
}

$files = discover($root, $paths);
if ($files === []) { fwrite(STDERR, "no test files found\n"); exit(2); }
$timingsFile = $root . '/.phpunit-timings.json';
$timings = is_file($timingsFile) ? (json_decode((string) file_get_contents($timingsFile), true) ?: []) : [];
$shards = split($files, $timings, min($opts['jobs'], count($files)), $root);

$work = sys_get_temp_dir() . '/kiption-shards-' . getmypid();
mkdir($work, 0775, true);
$started = microtime(true);
$running = [];
foreach ($shards as $i => $list) {
    $config = "{$work}/shard-{$i}.xml";
    $junit = "{$work}/shard-{$i}.junit.xml";
    writeConfig($root, $config, $list);
    $cmd = [PHP_BINARY, '-d', 'memory_limit=1G', $root . '/vendor/bin/phpunit', '--configuration', $config,
        '--log-junit', $junit, '--do-not-cache-result', '--no-progress', '--colors=never'];
    if ($opts['group'] !== null) array_push($cmd, '--group', $opts['group']);
    if ($opts['exclude'] !== null) array_push($cmd, '--exclude-group', $opts['exclude']);
    if ($opts['random'] !== null) array_push($cmd, '--order-by=random', '--random-order-seed=' . $opts['random']);
    if ($opts['stop']) $cmd[] = '--stop-on-failure';
    $out = "{$work}/shard-{$i}.out";
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']], $pipes, $root);
    if (!is_resource($proc)) { fwrite(STDERR, "cannot start worker {$i}\n"); exit(2); }
    $running[$i] = ['proc' => $proc, 'out' => $out, 'junit' => $junit, 'files' => count($list)];
}

$exit = 0;
$totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];
$newTimings = [];
foreach ($running as $i => $w) {
    $code = proc_close($w['proc']);
    $sum = junitSummary($w['junit'], $newTimings);
    foreach ($totals as $k => $_) $totals[$k] += $sum[$k] ?? 0;
    if ($code !== 0 && $code !== 1 && !isset($sum['tests'])) { // crashed before writing a log
        $exit = max($exit, 2);
        echo "worker {$i} died (exit {$code}):\n" . tail($w['out'], 40);
    } elseif ($code !== 0) {
        $exit = max($exit, 1);
        echo "worker {$i} failed:\n" . failureText($w['out']);
    }
}
if ($newTimings !== [] && $paths === [] && $opts['group'] === null && $opts['exclude'] === null) {
    file_put_contents($timingsFile, json_encode($newTimings, JSON_PRETTY_PRINT) . "\n");
}
if (($keep = getenv('TEST_KEEP_JUNIT')) !== false && $keep !== '') { // for comparing a parallel run to a serial one
    @mkdir($keep, 0775, true);
    foreach (glob($work . '/shard-*.junit.xml') ?: [] as $f) copy($f, $keep . '/' . basename($f));
}
$rm = static function (string $dir): void { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); };
$rm($work);

printf("\n%d tests, %d assertions, %d failures, %d errors, %d skipped, %d workers, %.1fs%s\n",
    $totals['tests'], $totals['assertions'], $totals['failures'], $totals['errors'], $totals['skipped'],
    count($shards), microtime(true) - $started, $opts['random'] !== null ? ", random seed {$opts['random']}" : '');
exit($exit);

// ---------------------------------------------------------------------------

function cpuCount(): int
{
    $n = (int) @shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu 2>/dev/null' : 'nproc 2>/dev/null');
    return $n > 0 ? $n : 4;
}

/** @param list<string> $paths @return list<string> absolute test file paths, sorted */
function discover(string $root, array $paths): array
{
    $roots = $paths !== [] ? array_map(static fn($p) => $p[0] === '/' ? $p : getcwd() . '/' . $p, $paths)
        : array_merge([$root . '/tests'], glob($root . '/app/Features/*/Tests', GLOB_ONLYDIR) ?: []);
    $found = [];
    foreach ($roots as $r) {
        if (is_file($r)) { $found[] = realpath($r); continue; }
        if (!is_dir($r)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) $found[] = $f->getRealPath();
        }
    }
    $found = array_values(array_unique($found));
    sort($found);
    return $found;
}

/** Longest-first greedy balance: each file goes to the lightest worker so far.
 *  @param list<string> $files @param array<string,float> $timings @return list<list<string>> */
function split(array $files, array $timings, int $n, string $root): array
{
    $weight = static function (string $f) use ($timings, $root): float {
        $rel = ltrim(substr($f, strlen($root)), '/');
        return $timings[$rel] ?? (filesize($f) / 4000.0 + 0.3); // first run: bigger file, more tests
    };
    usort($files, static fn($a, $b) => $weight($b) <=> $weight($a));
    $shards = array_fill(0, $n, []);
    $load = array_fill(0, $n, 0.0);
    foreach ($files as $f) {
        $i = array_search(min($load), $load, true);
        $shards[$i][] = $f;
        $load[$i] += $weight($f);
    }
    return array_values(array_filter($shards));
}

/** @param list<string> $list */
function writeConfig(string $root, string $target, array $list): void
{
    $dom = new DOMDocument();
    $dom->load($root . '/phpunit.xml');
    $cfg = $dom->documentElement;
    $cfg->setAttribute('bootstrap', $root . '/vendor/autoload.php');
    $cfg->setAttribute('colors', 'false');
    $suites = $dom->getElementsByTagName('testsuites')->item(0);
    while ($suites->firstChild) $suites->removeChild($suites->firstChild);
    $suite = $dom->createElement('testsuite');
    $suite->setAttribute('name', 'shard');
    foreach ($list as $f) $suite->appendChild($dom->createElement('file', $f));
    $suites->appendChild($suite);
    while (($src = $dom->getElementsByTagName('source')->item(0)) !== null) $src->parentNode->removeChild($src); // coverage-only
    $dom->save($target);
}

/** @param array<string,float> $timings filled with seconds per test file @return array<string,int> */
function junitSummary(string $junit, array &$timings): array
{
    if (!is_file($junit)) return [];
    $x = @simplexml_load_file($junit);
    if ($x === false) return [];
    $top = $x->testsuite;
    $sum = [];
    foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $k) $sum[$k] = (int) $top[$k];
    foreach ($x->xpath('//testsuite[@file]') ?: [] as $s) {
        $rel = ltrim(substr((string) $s['file'], strlen(dirname(__DIR__))), '/');
        $timings[$rel] = round((float) $s['time'], 3);
    }
    return $sum;
}

function tail(string $file, int $lines): string
{
    return implode("\n", array_slice(explode("\n", (string) file_get_contents($file)), -$lines)) . "\n";
}

/** The failure report PHPUnit prints after the run, without the banner. */
function failureText(string $file): string
{
    $t = (string) file_get_contents($file);
    $p = strpos($t, "\nThere w");
    return $p === false ? tail($file, 60) : substr($t, $p + 1);
}
