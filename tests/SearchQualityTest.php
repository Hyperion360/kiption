<?php
declare(strict_types=1);
namespace App\Tests;

use App\Repositories\SearchRepository;
use App\Tests\Support\{SearchArchive, TaxonomyCase};

final class SearchQualityTest extends TaxonomyCase
{
    public function test_declared_corpus_meets_identity_rank_and_recall_gates(): void
    {
        $fixture = require __DIR__ . '/fixtures/search/quality.php';
        $archive = new SearchArchive($this->db, $this->authorId, (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id']);
        $archive->clear();
        $keys = $tags = [];
        $distractorTag = $archive->tag('Companion Volumes and Other Tales of Imaginary Voyages');
        foreach ($fixture['stories'] as $story) {
            foreach ($story['tags'] as $name) $tags[$name] ??= $archive->tag($name);
        }
        // Approval/canonicalization precedes attachment, as it does in the editor.
        foreach ($fixture['aliases'] as $alias => $canonical) $archive->tag($alias, 'Search Fixture', $tags[$canonical]);
        foreach ($fixture['stories'] as $key => $story) {
            $options = ['tags' => array_map(static fn ($name) => $tags[$name], $story['tags']),
                'categories' => [$archive->category($story['category'])]] + $story;
            $keys[$archive->story($story['title'], $options)] = $key;
            for ($i = 0; $i < 6; $i++) {
                $title = preg_replace('/\S+$/u', 'Companion' . chr(65 + $i), $story['title']);
                $keys[$archive->story($title, ['tags' => [$distractorTag]] + $options)] = 'distractor-' . $key . '-' . $i;
            }
            foreach ([['validated' => 0], ['restricted' => 1], ['deleted' => '2026-01-02'], ['locked' => 'fixture-hash']] as $i => $gate) {
                $keys[$archive->story($story['title'], $gate + $options)] = 'hidden-' . $key . '-' . $i;
            }
        }
        $keys[$archive->story('Wonderlend Notebook')] = 'typo-exact';
        self::assertGreaterThanOrEqual(60, count($fixture['queries']));
        $repo = new SearchRepository($this->db);
        $failures = $reciprocalRanks = $recalls = $exactRanks = [];
        foreach ($fixture['queries'] as $case) {
            $result = $repo->searchWithTaxonomies($case['q'], $case['filters'] ?? [], 100, 0, 0);
            $actual = array_map(static fn ($row) => $keys[$row['id']], $result['rows']);
            $top = array_slice($actual, 0, 5);
            $errors = [];
            if (isset($case['first']) && ($actual[0] ?? null) !== $case['first']) $errors[] = 'first=' . $case['first'];
            if (isset($case['firstAny']) && !in_array($actual[0] ?? null, $case['firstAny'], true)) $errors[] = 'first outside allowed ties';
            if (isset($case['only'])) {
                $expected = $case['only']; $observed = $actual;
                sort($expected); sort($observed);
                if ($expected !== $observed) $errors[] = 'expected only ' . implode(',', $expected);
            }
            if (($case['none'] ?? false) && $actual !== []) $errors[] = 'expected no matches';
            if (array_filter($actual, static fn ($key) => str_starts_with($key, 'hidden-'))) $errors[] = 'visibility leak';
            if (isset($case['first'])) {
                $rank = array_search($case['first'], $top, true);
                $reciprocalRanks[] = $rank === false ? 0.0 : 1.0 / ($rank + 1);
                if ($case['kind'] === 'exact') $exactRanks[] = ($actual[0] ?? null) === $case['first'] ? 1 : 0;
            }
            if ($case['oneEdit'] ?? false) $recalls[] = in_array($case['relevant'] ?? $case['first'], $top, true) ? 1 : 0;
            if ($errors !== []) $failures[] = $case['kind'] . ' ' . $case['q'] . ': ' . implode('; ', $errors) . ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE);
        }
        self::assertNotEmpty($reciprocalRanks);
        self::assertNotEmpty($recalls);
        self::assertNotEmpty($exactRanks);
        $scores = sprintf('Cases=%d; exact rank one=%.3f; MRR@5=%.3f; one-edit recall@5=%.3f (%d cases)',
            count($fixture['queries']), array_sum($exactRanks) / count($exactRanks),
            array_sum($reciprocalRanks) / count($reciprocalRanks), array_sum($recalls) / count($recalls), count($recalls));
        fwrite(STDOUT, "\nSynthetic Search Quality: {$scores}\n");
        self::assertSame([], $failures, $scores . "\n" . implode("\n", $failures));
        self::assertSame(1, min($exactRanks), $scores);
        self::assertGreaterThanOrEqual(.90, array_sum($reciprocalRanks) / count($reciprocalRanks), $scores);
        self::assertGreaterThanOrEqual(.95, array_sum($recalls) / count($recalls), $scores);
    }
}
