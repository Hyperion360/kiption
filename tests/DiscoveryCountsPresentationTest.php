<?php
declare(strict_types=1);
namespace App\Tests;
use PHPUnit\Framework\Attributes\Small;

use App\Tests\Support\{SearchArchive, TaxonomyCase};

#[Small]
final class DiscoveryCountsPresentationTest extends TaxonomyCase
{
    private SearchArchive $archive;
    private int $fantasy;
    private int $gore;

    protected function setUp(): void
    {
        parent::setUp();
        $rating = (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id'];
        $this->archive = new SearchArchive($this->db, $this->authorId, $rating);
        $this->archive->clear();
        $this->fantasy = $this->archive->tag('Fantasy');
        $this->gore = $this->archive->tag('Gore');
        $category = $this->archive->category('gazette');
        $this->db->query('UPDATE categories SET name=? WHERE id=?', ['The Gazette', $category]);
        $this->archive->story('River One', ['completed' => 1, 'tags' => [$this->fantasy], 'categories' => [$category]]);
        $this->archive->story('River Two', ['tags' => [$this->fantasy, $this->gore]]);
    }

    private function document(string $body): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $body);
        return new \DOMXPath($document);
    }

    public function test_zero_one_many_totals_and_empty_later_pages_have_headings(): void
    {
        $client = $this->client();
        foreach ([['q' => 'absent'], ['q' => 'river', 'completed' => '1'], ['q' => 'river'], ['q' => 'river', 'page' => '9']] as $i => $query) {
            $response = $client->get('/search', $query);
            $this->assertSame(200, $response->status);
            $count = [0, 1, 2, 2][$i];
            $this->assertStringContainsString($count . ' Matching ' . ($count === 1 ? 'Story' : 'Stories'), $response->body);
        }
        $initial = $client->get('/search')->body;
        $this->assertStringNotContainsString('Matching Stories', $initial);
        $this->assertStringContainsString(\App\Lang::t('search.hint'), $initial);
        $this->assertStringContainsString('2 Matching Stories', $client->get('/browse/filter', ['page' => '9'])->body);
    }

    public function test_category_and_tag_labels_show_their_own_counts(): void
    {
        foreach (['/search', '/browse/filter'] as $path) {
            $body = $this->client()->get($path, ['tag' => [(string) $this->fantasy], 'xtag' => [(string) $this->gore]])->body;
            $xpath = $this->document($body);
            foreach (['tag' => 0, 'xtag' => 1] as $field => $count) {
                $label = $xpath->query('//label[input[@name="' . $field . '[]" and @value="' . $this->gore . '"]]')->item(0);
                $this->assertNotNull($label);
                $this->assertSame('Gore (' . $count . ')', trim($label->textContent));
            }
            $this->assertMatchesRegularExpression($path === '/search' ? '~The Gazette</span>\s*<span class="count"[^>]*>1</span>~' : '~The Gazette \(1\)~', $body);
            $this->assertStringContainsString(\App\Lang::t('search.category_counts_help'), $body);
            $this->assertStringContainsString(\App\Lang::t('search.tag_counts_help'), $body);
        }
    }

    public function test_native_status_and_language_options_preserve_selected_zero_values(): void
    {
        $body = $this->client()->get('/search', ['completed' => '0'])->body;
        $xpath = $this->document($body);
        $this->assertSame('In Progress 1', preg_replace('/\s+/', ' ', trim($xpath->query('//input[@name="completed" and @value="0" and @checked]/ancestor::label')->item(0)?->textContent ?? '')));
        $this->assertStringContainsString('1 Matching Story', $body);
        $this->assertStringNotContainsString('href="/story/view/fixture-1"', $body);
        foreach (['/search', '/browse/filter'] as $path) {
            $zero = $this->client()->get($path, ['language' => 'zz'])->body;
            $xpath = $this->document($zero);
            $this->assertSame('zz (0)', trim($xpath->query('//select[@name="language"]/option[@value="zz" and @selected]')->item(0)?->textContent ?? ''));
            $this->assertStringContainsString('0 Matching Stories', $zero);
        }
    }

    public function test_selected_aliases_and_capped_tags_remain_checked_once_at_zero(): void
    {
        for ($i = 0; $i < 101; $i++) $last = $this->archive->tag(sprintf('Tag %03d', $i), 'Overflow');
        $alias = $this->archive->tag('Fantasy Alias', 'Search Fixture', $this->fantasy);
        foreach (['/search', '/browse/filter'] as $path) {
            $body = $this->client()->get($path, ['tag' => [(string) $last, (string) $alias]])->body;
            $xpath = $this->document($body);
            foreach ([$last, $alias] as $id) {
                $this->assertSame(1, $xpath->query('//input[@name="tag[]" and @value="' . $id . '" and @checked]')->length);
                $label = $xpath->query('//label[input[@name="tag[]" and @value="' . $id . '"]]')->item(0);
                $this->assertStringEndsWith('(0)', trim($label->textContent));
            }
            $this->assertStringContainsString('Also Selected', $body);
        }
    }
}
