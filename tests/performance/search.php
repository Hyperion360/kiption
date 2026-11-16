<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Repositories\{BrowseFilterRepository, BrowseQuery, SearchRepository};
use App\Tests\Support\{AppLayout, SearchArchive, SearchBenchmark};
use Kip\Database;
use Kip\Migrations\Migrator;

function elapsed(callable $run): float
{
    $start = hrtime(true);
    $run();
    return (hrtime(true) - $start) / 1e6;
}

try { $options = SearchBenchmark::options(array_slice($argv, 1)); }
catch (InvalidArgumentException $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(2); }
$n = $options['stories'];
$iterations = $options['iterations'];
$dir = sys_get_temp_dir() . '/kiption-search-bench-' . bin2hex(random_bytes(12));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create temporary directory.');
$path = $dir . '/archive.sqlite';
$exitCode = 2;
try {
    $db = new Database('sqlite:' . $path);
    (new Migrator($db, AppLayout::migrations()))->migrate();
    App\Seeder::run($db);
    $author = (int) $db->one("SELECT id FROM users WHERE penname='Demo Author'")['id'];
    $member = (int) $db->one("SELECT id FROM users WHERE penname='betafriend'")['id'];
    $rating = (int) $db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
    (new SearchArchive($db, $author, $rating))->clear();
    $tags = $categories = $types = $writeIds = [];
    // Reuse prepared statements on the same connection. All normal index triggers remain enabled.
    $pdo = (new ReflectionProperty(Database::class, 'pdo'))->getValue($db);
    $insertStory = $pdo->prepare('INSERT INTO stories(title,slug,summary,author_id,rating_id,completed,validated,
        word_count,language,is_restricted,deleted_at,access_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $attachTag = $pdo->prepare('INSERT INTO story_tags(story_id,tag_id) VALUES (?,?)');
    $attachCategory = $pdo->prepare('INSERT INTO story_categories(story_id,category_id) VALUES (?,?)');
    $insertChapter = $pdo->prepare('INSERT INTO chapters(story_id,position,title,content,validated,word_count) VALUES (?,1,?,?,1,400)');
    $indexedBytes = 0;
    $hidden = 0;
    $languages = ['en', 'fr', 'es', 'de', 'pt', 'ja', 'ru', 'it'];
    $chapter = str_repeat('A traveler follows the river toward a quiet harbor. ', 40);
    $seedStart = hrtime(true);
    $db->begin();
    try {
        // Remove the seed taxonomy so the distribution is exactly fifty categories and ten types.
        $db->query('DELETE FROM tags');
        $db->query('DELETE FROM tag_types');
        $db->query('DELETE FROM categories');
        for ($i = 0; $i < 50; $i++) {
            $db->query('INSERT INTO categories(name,slug) VALUES (?,?)', ['Benchmark Category ' . $i, 'bench-category-' . $i]);
            $categories[] = (int) $db->lastInsertId();
        }
        for ($i = 0; $i < 10; $i++) {
            $db->query('INSERT INTO tag_types(name) VALUES (?)', ['Benchmark Type ' . $i]);
            $types[] = (int) $db->lastInsertId();
        }
        for ($i = 0; $i < 1999; $i++) {
            $db->query('INSERT INTO tags(name,tag_type_id) VALUES (?,?)', [$i === 0 ? 'Slow Burn' : sprintf('Tag%05d', $i), $types[$i % 10]]);
            $tags[] = (int) $db->lastInsertId();
        }
        $db->query('INSERT INTO tags(name,tag_type_id,canonical_id) VALUES (?,?,?)', ['Slowburn', $types[0], $tags[0]]);
        for ($i = 0; $i < $n; $i++) {
            $words = [];
            for ($j = 0; $j < 5; $j++) $words[] = sprintf('lex%05d', ($i * 5 + $j) % 50000);
            $title = ($i === 17 ? 'Wonderland Midnight Cartographer Lanterns Moonlight Forests Rivers Harbor ' : '')
                . implode(' ', $words) . ($i % 37 === 0 ? ' Wonderland Harbor' : '');
            $validated = 1; $restricted = 0; $deleted = $locked = null;
            if ($i % 100 < 10) {
                $hidden++;
                match ($i % 4) { 0 => $validated = 0, 1 => $restricted = 1,
                    2 => $locked = 'fixture-hash', 3 => $deleted = '2026-03-01' };
            }
            $insertStory->execute([$title, 'bench-' . $i, 'A synthetic archive story.', $author, $rating, $i % 2,
                $validated, 500 + ($i * 137) % 100000, $languages[$i % 8], $restricted, $deleted, $locked,
                sprintf('2026-01-%02dT00:00:00Z', 1 + $i % 28), sprintf('2026-02-%02dT00:00:00Z', 1 + $i % 28)]);
            $id = (int) $pdo->lastInsertId();
            if ($i < 100) $writeIds[] = $id;
            $indexedBytes += strlen($title);
            for ($j = 0; $j < 5; $j++) {
                $t = $j === 0 && ($i % 5 === 0 || $i === 17) ? 0 : 1 + ($i * 7 + $j * 101) % 1998;
                $attachTag->execute([$id, $tags[$t]]);
                $indexedBytes += strlen($t === 0 ? 'Slow Burn Slowburn' : sprintf('Tag%05d', $t)) + 1;
            }
            $attachCategory->execute([$id, $categories[$i % 50]]);
            $attachCategory->execute([$id, $categories[($i + 7) % 50]]);
            $insertChapter->execute([$id, 'The Journey', $chapter]);
        }
        $db->query('INSERT OR IGNORE INTO muted(user_id,author_id) VALUES (?,?)', [$member, $author]);
        $db->commit();
    } catch (Throwable $error) { $db->rollBack(); throw $error; }
    $seedMs = (hrtime(true) - $seedStart) / 1e6;
    unset($words, $title, $chapter);
    $db->query('ANALYZE');
    $db->query('PRAGMA wal_checkpoint(TRUNCATE)');
    // dbstat is mandatory. Missing support raises an error rather than inventing index bytes.
    $indexBytes = (int) $db->one("SELECT COALESCE(SUM(pgsize),0) n FROM dbstat WHERE name GLOB 'search_*' OR name GLOB 'idx_search_*'")['n'];
    if ($indexBytes === 0) throw new RuntimeException('Spelling index is absent; benchmark unavailable.');
    $search = new SearchRepository($db);
    $browse = new BrowseFilterRepository($db);
    $rare = 'lex' . substr(sprintf('%05d', min($n * 5, 50000) - 10), 0, 4);
    $cases = [
        ['exact', 'lex00086', []], ['common-prefix', 'lex0', []], ['rare-prefix', $rare, []],
        ['deletion', 'wonderlnd', []], ['insertion', 'wonderlaand', []], ['substitution', 'wonderlend', []],
        ['transposition', 'wondreland', []], ['eight-tokens', 'wonderlend midnigth cartogropher lantrens moonlgiht forsets rievrs harobr', []],
        ['tag', 'slow burn', []], ['synonym', 'slowburn', []], ['no-hit', 'zzqvwxplmn', []],
        ['include-exclude', 'lex', ['tag' => [$tags[0]], 'xtag' => [$tags[1]]]],
        ['category-or', '', ['category' => ['bench-category-1', 'bench-category-2']]],
        ['language', '', ['language' => 'en']], ['empty-search', '', []],
    ];
    $runs = [];
    foreach ($cases as [$name, $q, $filters]) $runs[$name] = fn () => $search->searchWithTaxonomies($q, $filters, 20, 0, 0);
    foreach (['updated', 'created', 'words', 'title'] as $sort) {
        $query = BrowseQuery::fromQuery(['sort' => $sort, 'cat' => ['bench-category-1', 'bench-category-2']]);
        $runs['browse-' . $sort] = fn () => $browse->page($query);
    }
    $query = BrowseQuery::fromQuery(['wmin' => '1000', 'wmax' => '50000', 'language' => 'en']);
    $runs['word-range'] = fn () => $browse->page($query);
    $runs['mute-on'] = fn () => $search->searchWithTaxonomies('wonderlend', [], 20, 0, $member, $member);
    $report = ['stories' => $n, 'iterations' => $iterations, 'php' => PHP_VERSION, 'sqlite' => $db->serverVersion(),
        'machine' => php_uname(), 'seed_ms' => $seedMs, 'indexed_utf8_bytes' => $indexedBytes, 'new_index_bytes' => $indexBytes,
        'word_count' => (int) $db->one('SELECT COUNT(*) n FROM search_words')['n'], 'database_bytes' => filesize($path),
        'dataset' => ['hidden_stories' => $hidden, 'tags' => 2000, 'tag_types' => 10, 'categories' => 50,
            'languages' => 8, 'tags_per_story' => 5, 'categories_per_story' => 2,
            'distinct_rotating_lexemes' => min($n * 5, 50000)],
        'thresholds' => ['read_p95_ms' => $n <= 10000 ? 150 : 500, 'read_max_ms' => $n <= 10000 ? 500 : 1500,
            'statements' => 1, 'php_peak_bytes' => 128 * 1024 * 1024, 'write_p95_ms_at_10k' => 50,
            'index_max_bytes' => $indexedBytes * 8 + 20 * 1024 * 1024],
        'queries' => [], 'failures' => [], 'planner_findings' => []];
    $actualDataset = $db->one("SELECT
        (SELECT COUNT(*) FROM stories) stories,
        (SELECT COUNT(*) FROM stories WHERE validated=0 OR is_restricted=1 OR deleted_at IS NOT NULL OR access_hash IS NOT NULL) hidden_stories,
        (SELECT COUNT(*) FROM tags) tags,
        (SELECT COUNT(*) FROM tag_types) tag_types,
        (SELECT COUNT(*) FROM categories) categories,
        (SELECT COUNT(DISTINCT language) FROM stories) languages,
        (SELECT COUNT(*) FROM story_tags) tag_attachments,
        (SELECT COUNT(*) FROM story_categories) category_attachments,
        (SELECT COUNT(*) FROM search_words WHERE word GLOB 'lex[0-9][0-9][0-9][0-9][0-9]') distinct_rotating_lexemes");
    $expectedDataset = ['stories' => $n, 'hidden_stories' => $hidden, 'tags' => 2000, 'tag_types' => 10,
        'categories' => 50, 'languages' => 8, 'tag_attachments' => $n * 5, 'category_attachments' => $n * 2,
        'distinct_rotating_lexemes' => min($n * 5, 50000)];
    foreach ($expectedDataset as $key => $expected) {
        if ((int) $actualDataset[$key] !== $expected) throw new RuntimeException('Unexpected generated dataset: ' . $key);
    }
    $report['dataset_actual'] = $actualDataset;
    foreach (['app/src/Repositories/SearchRepository.php', 'app/src/Repositories/BrowseFilterRepository.php',
        'app/src/Repositories/DiscoveryFold.php', 'app/src/Repositories/DiscoveryFilter.php',
        'app/src/Repositories/CategoryFilter.php', 'app/src/Repositories/TagFilter.php', 'app/src/Repositories/BrowseQuery.php',
        'app/src/Search/Spelling.php', 'app/src/Search/SpellingSql.php', 'tests/Support/SearchBenchmark.php',
        'app/Features/Search/migrations/071_search_words.php', 'tests/performance/search.php'] as $source) {
        $report['source_sha256'][$source] = hash_file('sha256', dirname(__DIR__, 2) . '/' . $source);
    }
    memory_reset_peak_usage();
    foreach ($runs as $name => $run) {
        $run();
        $samples = $counts = [];
        $last = [];
        $count = 0;
        $db->onQuery(static function () use (&$count): void { $count++; });
        for ($i = 0; $i < $iterations; $i++) {
            $count = 0;
            $samples[] = elapsed(function () use ($run, &$last): void { $last = $run(); });
            $counts[] = $count;
        }
        $db->onQuery(static function (): void {});
        if (min($counts) !== 1 || max($counts) !== 1) $report['failures'][] = $name . ': expected one statement every time';
        if ($name === 'eight-tokens' && !in_array('bench-17', array_column($last['rows'], 'slug'), true)) {
            $report['failures'][] = $name . ': intended positive fuzzy result absent';
        }
        if (in_array($name, ['no-hit', 'mute-on'], true)) {
            if ($last['total'] !== 0) $report['failures'][] = $name . ': expected zero results';
        } elseif ($last['total'] === 0) $report['failures'][] = $name . ': positive fixture matched nothing';
        if ($name === 'empty-search' && $last['rows'] !== []) $report['failures'][] = $name . ': initial search must not list stories';
        $statements = SearchBenchmark::capture($db, $run);
        if (count($statements) !== 1) throw new RuntimeException('Expected one captured statement for ' . $name);
        [$sql, $binds] = $statements[0];
        $explain = $db->all('EXPLAIN QUERY PLAN ' . $sql, $binds);
        $report['queries'][$name] = SearchBenchmark::stats($samples) + ['total' => $last['total'],
            'page_rows' => count($last['rows']), 'statement_min' => min($counts), 'statement_max' => max($counts), 'explain' => $explain];
        foreach ($explain as $row) {
            $detail = $row['detail'];
            if (str_starts_with($name, 'browse-') && str_contains($detail, 'USE TEMP B-TREE FOR ORDER BY')) {
                $report['failures'][] = $name . ': unexpected temporary result ordering';
            }
        }
        $scans = SearchBenchmark::postingsScans($explain);
        if ($scans !== []) {
            $report['planner_findings'][$name] = $scans;
            $report['failures'][] = $name . ': full spelling-postings scan requires architecture review';
        }
        if ($report['queries'][$name]['p95_ms'] > $report['thresholds']['read_p95_ms']
            || $report['queries'][$name]['max_ms'] > $report['thresholds']['read_max_ms']) {
            $report['failures'][] = $name . ': latency budget exceeded';
        }
    }
    $report['php_peak_bytes'] = memory_get_peak_usage(true);
    if ($report['php_peak_bytes'] > $report['thresholds']['php_peak_bytes']) $report['failures'][] = 'PHP memory budget exceeded';
    if ($indexBytes > $report['thresholds']['index_max_bytes']) $report['failures'][] = 'Index storage budget exceeded';
    $writes = ['insert' => [], 'title-rename' => [], 'tag-rename' => []];
    $renameTitle = $pdo->prepare('UPDATE stories SET title=? WHERE id=?');
    $renameTag = $pdo->prepare('UPDATE tags SET name=? WHERE id=?');
    $db->begin();
    try {
        for ($i = 0; $i < 100; $i++) {
            $writes['insert'][] = elapsed(function () use ($i, $insertStory, $attachTag, $attachCategory, $pdo, $author, $rating, $tags, $categories): void {
                $insertStory->execute(['Write Probe ' . $i, 'write-probe-' . $i, 'Synthetic write fixture.', $author, $rating,
                    0, 1, 1000, 'en', 0, null, null, '2026-01-01', '2026-01-01']);
                $id = (int) $pdo->lastInsertId();
                for ($j = 0; $j < 5; $j++) $attachTag->execute([$id, $tags[1 + ($i * 7 + $j * 101) % 1998]]);
                $attachCategory->execute([$id, $categories[$i % 50]]);
                $attachCategory->execute([$id, $categories[($i + 7) % 50]]);
            });
            $writes['title-rename'][] = elapsed(fn () => $renameTitle->execute(['Changed Probe ' . $i, $writeIds[$i]]));
            $writes['tag-rename'][] = elapsed(fn () => $renameTag->execute(['Renamedtag' . $i, $tags[$i]]));
        }
    } finally { $db->rollBack(); }
    foreach ($writes as $kind => $samples) {
        $report['writes'][$kind] = SearchBenchmark::stats($samples) + ['samples' => count($samples), 'rolled_back' => true];
        if ($n <= 10000 && $report['writes'][$kind]['p95_ms'] > 50) $report['failures'][] = $kind . ': write budget exceeded';
    }
    if ((int) $db->one("SELECT COUNT(*) n FROM stories WHERE slug LIKE 'write-probe-%'")['n'] !== 0) {
        throw new RuntimeException('Write probes were not rolled back.');
    }
    $report['failures'] = array_values(array_unique($report['failures']));
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    $output = fopen($options['json'], 'x');
    if ($output === false) throw new RuntimeException('Cannot create report without overwriting a file.');
    try {
        if (fwrite($output, $json) !== strlen($json)) throw new RuntimeException('Could not write the complete report.');
    } finally { fclose($output); }
    fwrite(STDOUT, ($report['failures'] === [] ? 'PASS' : 'FAIL') . ' ' . $options['json'] . "\n");
    $exitCode = $report['failures'] === [] ? 0 : 1;
} finally {
    unset($runs, $run, $search, $browse, $insertStory, $insertChapter, $attachTag, $attachCategory, $renameTitle, $renameTag, $pdo, $db);
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
exit($exitCode);
