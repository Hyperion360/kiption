<?php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Tests\Support\{QueryTap,SearchArchive,TaxonomyCase};
use Kip\{App,Database};
use Kip\Testing\TestClient;
#[Medium]
final class SearchLiveTest extends TaxonomyCase
{
    private SearchArchive $a;
    protected function setUp():void
    {
        parent::setUp();
        $this->a=new SearchArchive($this->db,$this->authorId,(int)$this->db->one('SELECT id FROM ratings ORDER BY id LIMIT 1')['id']);
        $this->a->clear();
    }
    private function request(array $get, ?int $viewer = null): \Kip\Http\Response
    {
        $app=new App(['app_dir'=>dirname(__DIR__).'/app','env'=>'prod','views'=>dirname(__DIR__).'/app/views',
            'db'=>['dsn'=>'sqlite:'.$this->path],'log_db'=>['dsn'=>'sqlite::memory:'],
            'static_cache'=>['dir'=>$this->cacheDir],'site_name'=>'Kiption','base_url'=>'https://archive.example']);
        $db=$app->container->make(Database::class); $queries=0;
        QueryTap::on($db,static function(string $sql)use(&$queries){
            if (!in_array($sql,['SELECT password_hash FROM users WHERE id = ?',
                \App\SessionGuard::SQL],true)) $queries++;
        });
        try {
            $client = new TestClient($app);
            if ($viewer !== null) $client = $client->actingAs($viewer);
            $r = $client->get('/search', $get);
        }
        finally { QueryTap::off($db); }
        $this->assertSame(200,$r->status); $this->assertSame(1,$queries);
        return $r;
    }
    public function test_live_uses_same_escaped_story_fragment_and_private_headers():void
    {
        $this->a->story('River <img src=x onerror=alert(1)>');
        $full=$this->request(['q'=>'river']);
        $live=$this->request(['q'=>'river','live'=>'1']);
        $this->assertSame('application/json; charset=utf-8',$live->headers['Content-Type']);
        $this->assertSame('private, no-store',$live->headers['Cache-Control']);
        $this->assertSame('Cookie',$live->headers['Vary']);
        $this->assertSame('noindex',$live->headers['X-Robots-Tag']);
        $data=json_decode($live->body,true,512,JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($data['html'],$full->body);
        $this->assertStringNotContainsString('<img src=x',$data['html']);
        $this->assertSame('River <img src=x onerror=alert(1)>',$data['suggestions'][0]['label']);
        $this->assertStringStartsWith('/story/view/',$data['suggestions'][0]['url']);
        $this->assertIsArray($data['counts']);
    }
    public function test_all_hidden_story_classes_and_pending_tags_stay_out_of_live_payload():void
    {
        foreach ([['validated'=>0],['restricted'=>1],['deleted'=>'2026-01-02'],['locked'=>'hash']] as $o)
            $this->a->story('Secretcanary River',$o);
        $public=$this->a->story('Public River');
        $type=(int)$this->db->one('SELECT id FROM tag_types ORDER BY id LIMIT 1')['id'];
        $this->db->query('INSERT INTO tag_suggestions(tag_type_id,name,suggested_by,story_id,status,created_at) VALUES (?,?,?,?,?,?)',
            [$type,'Secretcanary',$this->authorId,$public,'pending','2026-01-01T00:00:00Z']);
        $data=json_decode($this->request(['q'=>'secretcanary','live'=>'1'])->body,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame([],$data['suggestions']);
        $this->assertStringNotContainsString('/story/view/',$data['html']);
        $this->assertSame('0 matching stories.',$data['message']);
    }
    public function test_malformed_arrays_and_empty_query_preserve_one_statement():void
    {
        foreach ([['q'=>['x'],'rating'=>['1'],'category'=>[['a']],'page'=>['1'],'live'=>'1'],
            ['q'=>"\xff",'language'=>['en'],'live'=>'1'],['live'=>'1'],['completed'=>'0','live'=>'1']] as $get) {
            $r=$this->request($get);
            $this->assertIsArray(json_decode($r->body,true,512,JSON_THROW_ON_ERROR));
        }
        $r=$this->request(['q'=>'river','live'=>['1']]);
        $this->assertStringContainsString('text/html',$r->headers['Content-Type']);
    }
    private function document(string $html): \DOMXPath
    {
        $dom=new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        return new \DOMXPath($dom);
    }
    public function test_wip_pager_keeps_zero_and_every_active_filter():void
    {
        $a=$this->a->category('page-a');$b=$this->a->category('page-b');
        $tag=$this->a->tag('Page Tag');$excluded=$this->a->tag('Excluded Tag');
        for($i=0;$i<21;$i++) $this->a->story('River WIP '.$i,['tags'=>[$tag],'categories'=>[$i%2?$a:$b]]);
        $this->a->story('River Completed Canary',['completed'=>1,'tags'=>[$tag],'categories'=>[$a]]);
        $get=['q'=>'river','completed'=>'0','category'=>['page-a','page-b'],'tag'=>[$tag],
            'xtag'=>[$excluded],'language'=>'en','sort'=>'updated'];
        $first=$this->request($get);$xp=$this->document($first->body);
        $href=$xp->query('//nav[contains(@class,"pager")]/a[@rel="next"]')->item(0)->getAttribute('href');
        parse_str(parse_url($href,PHP_URL_QUERY),$next);
        $this->assertSame('0',$next['completed']);$this->assertSame(['page-a','page-b'],$next['category']);
        $this->assertSame([(string)$tag],$next['tag']);$this->assertSame([(string)$excluded],$next['xtag']);
        $this->assertSame('updated',$next['sort']);$this->assertSame('en',$next['language']);
        $second=$this->request($next);
        $this->assertStringNotContainsString('River Completed Canary',$second->body);
        $xp=$this->document($second->body);
        $this->assertSame(1,$xp->query('//ul[contains(@class,"story-list")]/li')->length);
        $back=$xp->query('//nav[contains(@class,"pager")]/a[@rel="prev"]')->item(0)->getAttribute('href');
        parse_str(parse_url($back,PHP_URL_QUERY),$previous);$this->assertSame('0',$previous['completed']);
    }
    public function test_unchecking_tag_has_no_hidden_duplicate_to_resubmit():void
    {
        $tag=$this->a->tag('Chosen Tag');
        $this->a->story('River Tagged',['tags'=>[$tag]]);$this->a->story('River Untagged');
        $first=$this->request(['q'=>'river','tag'=>[$tag]]);$xp=$this->document($first->body);
        $this->assertSame(0,$xp->query('//form[@action="/search"]//input[@type="hidden" and (@name="tag" or @name="tag[]")]')->length);
        $this->assertSame(1,$xp->query('//form[@action="/search"]//input[@name="tag[]" and @checked]')->length);
        $this->assertStringNotContainsString('River Untagged',$first->body);
        // Unchecked checkboxes are absent from GET submission; no hidden copy may restore them.
        $second=$this->request(['q'=>'river']);
        $this->assertStringContainsString('River Untagged',$second->body);
        $this->assertStringContainsString('River Tagged',$second->body);
    }
    public function test_quote_only_is_empty_in_repository_full_page_and_live_response():void
    {
        $this->a->story('Quote Canary');
        $r=(new \App\Repositories\SearchRepository($this->db))->search('"',[],20,0,0);
        $this->assertSame(['rows'=>[],'hasMore'=>false,'mode'=>'none'],$r);
        $full=$this->request(['q'=>'"']);$this->assertStringNotContainsString('Quote Canary',$full->body);
        $live=json_decode($this->request(['q'=>'"','live'=>'1'])->body,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame([],$live['suggestions']);$this->assertStringNotContainsString('Quote Canary',$live['html']);
    }


    public function test_live_counts_and_suggestions_match_each_rendered_facet_without_private_variation(): void
    {
        $cat = $this->a->category('visible');
        $tag = $this->a->tag('Visible Tag');
        $this->a->story('River Public', ['categories' => [$cat], 'tags' => [$tag]]);
        $get = ['q' => 'river', 'completed' => '0', 'tag' => [$tag], 'live' => '1'];
        $before = json_decode($this->request($get)->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1 matching story.', $before['message']);
        $this->assertSame(1, $before['counts']['category:visible']);
        $this->assertSame(1, $before['counts']['tag:' . $tag]);
        $this->assertSame(1, $before['counts']['status:0']);
        $full = $this->document($this->request(array_diff_key($get, ['live' => 1]))->body);
        foreach ($full->query('//form[@action="/search"]//*[@data-search-count]') as $el) {
            $key = $el->getAttribute('data-search-count');
            $n = (string) ($before['counts'][$key] ?? 0);
            $label = $el->getAttribute('data-count-label');
            $this->assertSame($label === '' ? $n : $label . ' (' . $n . ')', trim($el->textContent));
        }
        foreach ([['validated' => 0], ['restricted' => 1], ['deleted' => '2026-01-02'], ['locked' => 'hash']] as $options) {
            $this->a->story('River Secretcanary', $options + ['categories' => [$cat], 'tags' => [$tag]]);
        }
        $after = json_decode($this->request($get)->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($before, $after);
    }

    public function test_muted_authors_stay_out_of_member_live_counts_and_suggestions(): void
    {
        $this->a->story('River Visible');
        $this->a->story('River Muted', ['author' => $this->adminId]);
        $this->db->query('INSERT INTO muted(user_id, author_id) VALUES (?, ?)', [$this->memberId, $this->adminId]);
        $live = json_decode($this->request(['q' => 'river', 'live' => '1'], $this->memberId)->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1 matching story.', $live['message']);
        $this->assertStringNotContainsString('River Muted', $live['html']);
        $this->assertSame(['River Visible'], array_column($live['suggestions'], 'label'));
    }

    public function test_suggestions_use_only_five_current_page_rows_and_minimal_fields(): void
    {
        for ($i = 0; $i < 21; $i++) $this->a->story('River ' . $i);
        foreach ([1 => 5, 2 => 1] as $page => $count) {
            $live = json_decode($this->request(['q' => 'river', 'page' => (string) $page, 'live' => '1'])->body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount($count, $live['suggestions']);
            $xpath = $this->document($live['html']);
            $links = $xpath->query('//ul[contains(@class,"story-list")]//a[@class="story-title"]');
            foreach ($live['suggestions'] as $i => $suggestion) {
                $this->assertSame(['label', 'url'], array_keys($suggestion));
                $this->assertSame($links->item($i)->getAttribute('href'), $suggestion['url']);
                $this->assertSame(trim($links->item($i)->textContent), $suggestion['label']);
            }
        }
    }

    public function test_normalized_tag_name_boundary_matches_identically_in_full_and_live_results(): void
    {
        $prefix = str_repeat('x', 63);
        $short = $this->a->tag($prefix);
        $boundary = $this->a->tag($prefix . ' ');
        $this->a->story('River Wrong', ['tags' => [$short]]);
        $this->a->story('River Boundary', ['tags' => [$boundary]]);
        foreach (['tags' => 'River Boundary', 'xtags' => 'River Wrong'] as $field => $title) {
            $query = ['q' => 'river', $field => $prefix . ' suffix'];
            $full = $this->request($query);
            $live = json_decode($this->request($query + ['live' => '1'])->body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString($live['html'], $full->body);
            $this->assertSame([$title], array_column($live['suggestions'], 'label'));
            $this->assertSame('1 matching story.', $live['message']);
        }
    }

    public function test_query_bounds_initial_hint_and_far_page_stay_valid(): void
    {
        $this->a->story('River One');
        $empty = json_decode($this->request(['live' => '1'])->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(\App\Lang::t('search.hint'), $empty['message']);
        $this->assertSame([], $empty['suggestions']);
        $huge = json_decode($this->request(['q' => 'river', 'page' => str_repeat('9', 40), 'live' => '1'])->body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $huge['suggestions']);
        $this->assertStringContainsString('1 Matching Story', $huge['html']);
        $full = $this->document($this->request(['q' => str_repeat('я', 201)])->body);
        $this->assertSame(200, mb_strlen($full->query('//form[@data-js-module="search"]//input[@name="q"]')->item(0)->getAttribute('value'), 'UTF-8'));
        $character = $this->document($this->request(['character' => '42'])->body);
        $this->assertSame('42', $character->query('//form[@data-js-module="search"]//input[@name="character" and @type="hidden"]')->item(0)->getAttribute('value'));
    }

}
