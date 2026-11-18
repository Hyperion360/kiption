<?php // scripts/test-changed.php
declare(strict_types=1);

/**
 * Inner-loop test runner: run only the tests your change can reach.
 *
 *   php scripts/test-changed.php [--base=REF] [--with-large] [--dry-run] [--jobs=N]
 *
 * Reads the paths you changed (git diff against --base, default HEAD, plus
 * untracked files) and maps each one:
 *
 *   app/Features/<Name>/Tests/...     that test file, or that slice's tests
 *   app/Features/<Name>/<other>       every test in that slice (controller, views)
 *   tests/<Name>Test.php              that one test file
 *   tests/js/...                      the Node tests only (test.php --js-only)
 *   app/Features/<Name>/<other>       with no Tests folder of its own: the full
 *                                     suite, since other tests may cover it
 *   docs, *.md, changelog.d, .gitignore   nothing to run
 *   anything else                     the full suite: app/src, app/migrations,
 *                                     a feature's migrations, app/views,
 *                                     tests/Support, fixtures, config, bin,
 *                                     public, composer files, phpunit.xml
 *
 * A slice run skips Large tests (they spawn processes and servers) and the
 * methods tagged "process"; pass --with-large to include them. A full-suite
 * fallback always runs everything. The full run is still the push gate:
 *
 *   php scripts/test.php
 *
 * Slice runs trade reach for speed: a change inside one feature folder does
 * not run the other features' tests or the top-level tests that cross
 * features. If your change touches shared behavior, run the full suite.
 */

$root = dirname(__DIR__);
$base = 'HEAD'; $withLarge = false; $dry = false; $pass = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--base=(.+)$/', $arg, $m)) $base = $m[1];
    elseif ($arg === '--with-large') $withLarge = true;
    elseif ($arg === '--dry-run') $dry = true;
    elseif (str_starts_with($arg, '--jobs=')) $pass[] = $arg;
    else { fwrite(STDERR, "unknown option {$arg}\n"); exit(2); }
}

$changed = lines(git($root, ['diff', '--name-only', $base, '--']));
$changed = array_values(array_unique(array_merge($changed, lines(git($root, ['ls-files', '--others', '--exclude-standard'])))));
if ($changed === []) { echo "nothing changed against {$base}; nothing to run\n"; exit(0); }

$targets = []; $full = null; $js = false;
foreach ($changed as $path) {
    $r = route($path);
    if ($r === 'full') { $full = $path; break; }
    if ($r === 'js') { $js = true; continue; }
    if ($r !== 'none') $targets[$r] = true;
}

if ($full !== null) {
    echo "full suite: {$full} is outside any slice\n";
    $cmd = [PHP_BINARY, $root . '/scripts/test.php', ...$pass];
} elseif ($targets === [] && $js) {
    echo "only the Node tests changed\n";
    $cmd = [PHP_BINARY, $root . '/scripts/test.php', '--js-only'];
} elseif ($targets === []) {
    echo "only docs changed; nothing to run\n";
    exit(0);
} else {
    $paths = array_keys($targets);
    sort($paths);
    echo "slice run: " . implode(' ', $paths) . "\n";
    $cmd = [PHP_BINARY, $root . '/scripts/test.php', ...$pass];
    if (!$withLarge) {
        array_push($cmd, '--exclude-group=large,process');
        echo "skipping Large tests and process-spawning methods (use --with-large to include them)\n";
    }
    array_push($cmd, ...$paths);
}
$jsToo = $js && $full === null && $targets !== []; // slices ran, and the Node tests changed too
if ($dry) {
    echo implode(' ', array_map('escapeshellarg', $cmd)) . "\n";
    if ($jsToo) echo implode(' ', array_map('escapeshellarg', [PHP_BINARY, $root . '/scripts/test.php', '--js-only'])) . "\n";
    exit(0);
}
$proc = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
$code = proc_close($proc);
if ($jsToo) {
    $proc = proc_open([PHP_BINARY, $root . '/scripts/test.php', '--js-only'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    $code = max($code, proc_close($proc));
}
exit($code);

// ---------------------------------------------------------------------------

/** @return string 'full', 'none', or a path (relative to the repo root) to run */
function route(string $p): string
{
    if (preg_match('#^app/Features/([^/]+)/Tests/(.+Test\.php)$#', $p, $m)) return "app/Features/{$m[1]}/Tests/{$m[2]}";
    if (preg_match('#^app/Features/([^/]+)/Tests/#', $p, $m)) return "app/Features/{$m[1]}/Tests"; // a helper or fixture in the slice
    if (preg_match('#^app/Features/([^/]+)/migrations/#', $p)) return 'full'; // changes the schema every DB test builds
    if (preg_match('#^app/Features/([^/]+)/#', $p, $m)) {
        // No Tests folder of its own: top-level tests may cover it, so run everything rather than nothing.
        return is_dir(dirname(__DIR__) . "/app/Features/{$m[1]}/Tests") ? "app/Features/{$m[1]}/Tests" : 'full';
    }
    if (preg_match('#^tests/[A-Za-z0-9_]+Test\.php$#', $p)) return $p;
    if (preg_match('#^tests/js/#', $p)) return 'js';
    if (preg_match('#^(docs/|changelog\.d/|[^/]+\.md$|\.gitignore$|\.github/)#', $p)) return 'none';
    return 'full';
}

/** @param list<string> $args */
function git(string $root, array $args): string
{
    $cmd = 'git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
    return (string) shell_exec($cmd);
}

/** @return list<string> */
function lines(string $s): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $s)), static fn($l) => $l !== ''));
}
