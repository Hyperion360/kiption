<?php
declare(strict_types=1);
namespace App\Tests;

use App\Repositories\{BrowseFilterRepository, BrowseQuery, SearchRepository};
use App\Tests\Support\{QueryTap, SearchArchive, TaxonomyCase};
use Kip\{App, Database};
use Kip\Testing\TestClient;

final class CategoryDiscoveryTest extends TaxonomyCase
{
    private SearchArchive $archive;
    private int $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archive = new SearchArchive($this->db, $this->authorId, (int) $this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id']);
        $this->archive->clear();
        $a = $this->archive->category('multi-a');
        $b = $this->archive->category('multi-b');
        $this->tag = $this->archive->tag('Chosen');
        $this->archive->story('River A', ['categories' => [$a], 'tags' => [$this->tag]]);
        $this->archive->story('River B', ['categories' => [$b]]);
        $this->archive->story('River Both', ['categories' => [$a, $b], 'tags' => [$this->tag]]);
        $this->archive->story('River Neither');
    }

    private function get(string $path, array $query): \Kip\Http\Response
    {
        $root = dirname(__DIR__);
        $app = new App([
            'app_dir' => $root . '/app', 'env' => 'prod', 'views' => $root . '/app/views', 'items_per_page' => 2,
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'static_cache' => ['dir' => $this->cacheDir], 'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
        $db = $app->container->make(Database::class);
        $queries = 0;
        QueryTap::on($db, static function () use (&$queries): void { $queries++; });
        try {
            $response = (new TestClient($app))->get($path, $query);
        } finally {
            QueryTap::off($db);
        }
        self::assertSame(200, $response->status);
        self::assertSame(1, $queries);
        return $response;
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    public function test_multiple_categories_are_or_and_tag_filters_remain_and(): void
    {
        $search = new SearchRepository($this->db);
        $result = $search->search('river', ['category' => ['multi-a', 'multi-b']], 20, 0, 0);
        self::assertCount(3, $result['rows']);
        self::assertCount(3, array_unique(array_column($result['rows'], 'slug')));
        self::assertCount(2, $search->search('river', ['category' => ['multi-a', 'multi-b'], 'tag' => [$this->tag]], 20, 0, 0)['rows']);
        self::assertCount(2, $search->search('', ['category' => 'multi-a'], 20, 0, 0)['rows']);
        self::assertSame([], $search->search('', ['category' => ['unknown-category']], 20, 0, 0)['rows']);
        self::assertSame([], $search->search('""', [], 20, 0, 0)['rows']);
        $browse = (new BrowseFilterRepository($this->db))->page(BrowseQuery::fromQuery(['cat' => ['multi-a', 'multi-b']]));
        self::assertCount(3, $browse['rows']);
        $filtered = (new BrowseFilterRepository($this->db))->page(BrowseQuery::fromQuery(['cat' => ['multi-a', 'multi-b'], 'tag' => [$this->tag]]));
        self::assertCount(2, $filtered['rows']);
    }

    public function test_search_and_browse_forms_and_search_pager_keep_category_arrays(): void
    {
        $first = $this->get('/search', ['q' => 'river', 'category' => ['multi-a', 'multi-b'], 'xtags' => 'Gore', 'sort' => 'updated']);
        $xpath = $this->document($first->body);
        self::assertSame(2, $xpath->query('//input[@name="category[]" and @checked]')->length);
        $href = $xpath->query('//nav[contains(@class,"pager")]/a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $next);
        self::assertSame(['multi-a', 'multi-b'], $next['category']);
        self::assertSame('Gore', $next['xtags']);
        self::assertSame('updated', $next['sort']);
        $second = $this->get('/search', $next);
        $xpath = $this->document($second->body);
        self::assertSame(1, $xpath->query('//ul[contains(@class,"story-list")]/li')->length);
        $back = $xpath->query('//nav[contains(@class,"pager")]/a[@rel="prev"]')->item(0)->getAttribute('href');
        parse_str(parse_url($back, PHP_URL_QUERY), $previous);
        self::assertSame(['multi-a', 'multi-b'], $previous['category']);
        $browse = $this->document($this->get('/browse/filter', ['cat' => ['multi-a', 'multi-b']])->body);
        self::assertSame(2, $browse->query('//input[@name="cat[]" and @checked]')->length);
        self::assertSame(3, $browse->query('//ul[contains(@class,"story-list")]/li')->length);
    }

    public function test_removing_a_category_removes_its_only_stories(): void
    {
        foreach (['/search' => 'category', '/browse/filter' => 'cat'] as $path => $name) {
            $xpath = $this->document($this->get($path, [$name => ['multi-a']])->body);
            self::assertSame(1, $xpath->query('//input[@name="' . $name . '[]" and @checked]')->length);
            self::assertSame(2, $xpath->query('//ul[contains(@class,"story-list")]/li')->length);
            self::assertSame(0, $xpath->query('//ul[contains(@class,"story-list")]//*[normalize-space(text())="River B"]')->length);
        }
    }

    public function test_bare_search_keeps_refine_closed_and_categories_unselected(): void
    {
        $xpath = $this->document($this->get('/search', [])->body);
        self::assertSame(0, $xpath->query('//details[contains(@class,"search-refine") and @open]')->length);
        self::assertSame(0, $xpath->query('//input[@name="category[]" and @checked]')->length);
    }

    public function test_browse_pager_preserves_categories_excluded_names_and_sort(): void
    {
        $category = $this->archive->category('multi-a');
        for ($i = 0; $i < 20; $i++) {
            $this->archive->story('More River ' . $i, ['categories' => [$category]]);
        }
        $xpath = $this->document($this->get('/browse/filter', [
            'cat' => ['multi-a', 'multi-b'], 'xtags' => 'Gore', 'sort' => 'title',
        ])->body);
        $href = $xpath->query('//nav[contains(@class,"pager")]/a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($href, PHP_URL_QUERY), $next);
        self::assertSame(['multi-a', 'multi-b'], $next['cat']);
        self::assertSame('Gore', $next['xtags']);
        self::assertSame('title', $next['sort']);
        $second = $this->document($this->get('/browse/filter', $next)->body);
        self::assertSame(2, $second->query('//input[@name="cat[]" and @checked]')->length);
        self::assertSame(3, $second->query('//ul[contains(@class,"story-list")]/li')->length);
    }
}
