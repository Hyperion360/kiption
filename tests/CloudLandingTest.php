<?php // tests/CloudLandingTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The /cloud landing layer: when cloud_marketing carries a 'landing' array
 *  the page renders nav, hero, proof band, feature blocks with decorative
 *  mocks, offer, plans, FAQ, and close from config; without it the page is
 *  the plain hero + body shape. Fixtures here are neutral on purpose: the
 *  real copy lives in the gitignored config.marketing.php. */
final class CloudLandingTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-lp-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)',
            ['cloud', 'Hosted: your archive, kept running', "Lede paragraph.\n\nTerms record paragraph."]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @param array<string, mixed> $extra */
    private function app(array $extra = []): App
    {
        return new App(array_merge([
            'app_dir' => dirname(__DIR__) . '/app', 'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-lp.log', 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function marketing(): array
    {
        $base = 'https://cloud.example';
        return [
            'cta_label' => 'Start Now', 'cta_url' => $base . '/start',
            'plans' => [
                ['name' => 'Alpha', 'blurb' => 'For one.', 'price' => '$9', 'period' => '/ month', 'rows' => ['Row one'], 'cta' => 'Start Alpha', 'url' => $base . '/start?plan=alpha'],
            ],
            'offer' => 'Callout that the landing replaces.',
            'close' => ['heading' => 'Close Here', 'body' => 'Closing words.'],
            'landing' => [
                'meta' => ['title' => 'Meta Title Here', 'description' => 'Meta description here.'],
                'nav' => [['How It Works', '#how-it-works'], ['Features', $base . '/features'], ['Migration', $base . '/migration'],
                          ['Pricing', '#pricing'], ['Compare', $base . '/compare'], ['Blog', $base . '/blog'], ['Docs', $base . '/docs'], ['Status', $base . '/status']],
                'hero' => ['kicker' => 'Kicker Words', 'h1' => 'Hero Headline Words', 'lede' => 'Hero lede sentence.', 'from' => 'From $9 a month',
                           'secondary' => ['See How It Works', '#how-it-works'], 'trust' => ['Trust one', 'Trust two']],
                'editor' => ['story' => 'A Story', 'chapter' => 'Chapter 1', 'status' => 'Draft', 'tools' => ['Bold'], 'text' => ['First para.', 'Second para.'],
                             'themes' => ['Paper', 'Sepia', 'Night'], 'focus' => 'Focus Mode', 'caption' => 'Illustration of the writing screen'],
                'why' => ['label' => 'Why', 'h2' => 'Why Heading', 'intro' => 'Why intro.', 'source' => ['Read It', 'https://source.example/x'], 'items' => ['One', 'Two', 'Three']],
                'how' => ['label' => 'How It Works', 'h2' => 'How Heading', 'features' => [
                    ['id' => 'editor', 'title' => 'Editor Block', 'body' => 'Body.', 'objection' => 'Objection?', 'answer' => 'Answer.', 'mock' => ['type' => 'editor']],
                    ['id' => 'migration', 'title' => 'Migration Block', 'body' => 'Body.', 'objection' => 'Objection?', 'answer' => 'Answer.',
                     'mock' => ['type' => 'report', 'title' => 'Report', 'sub' => 'Sub', 'cols' => ['Old', 'New'], 'rows' => [['Stories', '1', '1']], 'foot' => 'Match.']],
                    ['id' => 'ops', 'title' => 'Ops Block', 'body' => 'Body.', 'objection' => 'Objection?', 'answer' => 'Answer.',
                     'mock' => ['type' => 'log', 'title' => 'Log', 'items' => [['Backup', 'Backup']], 'foot' => 'Done.']],
                    ['id' => 'speed', 'title' => 'Speed Block', 'body' => 'Body.', 'objection' => 'Objection?', 'answer' => 'Answer.',
                     'mock' => ['type' => 'chips', 'items' => ['Chip A', 'Chip B']]],
                    ['id' => 'exit', 'title' => 'Exit Block', 'body' => 'Body.', 'objection' => 'Objection?', 'answer' => 'Answer.',
                     'mock' => ['type' => 'file', 'title' => 'archive.sqlite', 'sub' => 'One file', 'downloads' => ['EPUB', 'HTML']]],
                ]],
                'offer' => ['heading' => 'Offer Heading', 'body' => 'Offer body.', 'link' => ['Read the Terms', 'https://cloud.example/terms']],

                'pricing' => ['label' => 'Pricing', 'h2' => 'Pricing Heading', 'sub' => 'Pricing sub.'],
                'faq' => ['label' => 'Questions', 'h2' => 'FAQ Heading', 'sub' => 'FAQ sub.', 'items' => [['Q one?', 'A one.'], ['Q two?', 'A two.']]],
                'close_note' => 'Refund note',
            ],
        ];
    }

    public function test_without_landing_config_no_landing_markup_renders(): void
    {
        $m = $this->marketing(); unset($m['landing']);
        $r = (new TestClient($this->app(['cloud_marketing' => $m])))->get('/cloud');
        $this->assertSame(200, $r->status);
        $this->assertStringNotContainsString('lp-', $r->body);
        $this->assertStringContainsString('cloud-hero', $r->body);
    }

    public function test_nav_lists_every_item_in_order_with_absolute_subdomain_links(): void
    {
        $r = (new TestClient($this->app(['cloud_marketing' => $this->marketing()])))->get('/cloud');
        preg_match('#<nav class="cloud-nav".*?</nav>#s', $r->body, $nav);
        $this->assertNotEmpty($nav, 'page-level nav present');
        preg_match_all('#<a [^>]*href="([^"]+)"[^>]*>([^<]+)</a>#', $nav[0], $m, PREG_SET_ORDER);
        $labels = array_map(fn($x) => $x[2], $m);
        $this->assertSame(['How It Works', 'Features', 'Migration', 'Pricing', 'Compare', 'Blog', 'Docs', 'Status', 'Start Now'], $labels);
        $this->assertSame('https://cloud.example/blog', $m[5][1]);
        $this->assertSame('#how-it-works', $m[0][1]);
        $this->assertSame('https://cloud.example/start', $m[8][1], 'the nav CTA is the single signup button');
    }

    public function test_hero_comes_from_landing_config_and_the_body_record_stays_below(): void
    {
        $r = (new TestClient($this->app(['cloud_marketing' => $this->marketing()])))->get('/cloud');
        $this->assertStringContainsString('<h1>Hero Headline Words</h1>', $r->body);
        $this->assertStringContainsString('Kicker Words', $r->body);
        $this->assertStringContainsString('Hero lede sentence.', $r->body);
        $this->assertStringContainsString('From $9 a month', $r->body);
        $this->assertStringContainsString('Lede paragraph.', $r->body, 'the page row body is kept whole as the record below');
        $this->assertStringContainsString('Terms record paragraph.', $r->body);
        $this->assertStringContainsString('<title>Meta Title Here', $r->body);
        $this->assertStringContainsString('content="Meta description here."', $r->body);
    }

    public function test_every_block_renders_with_its_anchor_and_decorative_mocks_are_hidden_from_readers(): void
    {
        $r = (new TestClient($this->app(['cloud_marketing' => $this->marketing()])))->get('/cloud');
        foreach (['why-archives-close', 'how-it-works', 'feature-editor', 'feature-migration', 'feature-ops', 'feature-speed', 'feature-exit', 'pricing', 'plans', 'faq'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $r->body, $id);
        }
        $this->assertSame(5, substr_count($r->body, 'class="lp-feature-mock" aria-hidden="true"'));
        $this->assertSame(2, substr_count($r->body, '<details class="lp-faq">'), 'the FAQ is native details, no script');
        $this->assertStringContainsString('Offer Heading', $r->body);
        $this->assertStringContainsString('href="https://cloud.example/terms">Read the Terms</a>', $r->body);
        $this->assertStringNotContainsString('Callout that the landing replaces.', $r->body, 'the offer lives in its band, not twice');
        $this->assertStringContainsString('Pricing Heading', $r->body);
        $this->assertStringContainsString('href="https://cloud.example/start?plan=alpha"', $r->body);
        $this->assertStringContainsString('Refund note', $r->body);
        $this->assertStringContainsString('href="https://source.example/x"', $r->body);
    }

    public function test_the_landing_page_adds_no_inline_script_or_handlers(): void
    {
        $r = (new TestClient($this->app(['cloud_marketing' => $this->marketing()])))->get('/cloud');
        preg_match('#<article class="cloud-page[^"]*".*?</article>#s', $r->body, $a);
        $this->assertNotEmpty($a);
        $this->assertStringNotContainsString('<script', $a[0]);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $a[0]);
    }

    public function test_landing_values_are_escaped(): void
    {
        $m = $this->marketing();
        $m['landing']['hero']['h1'] = '<b>x</b> & y';
        $m['landing']['faq']['items'][0][1] = '<script>alert(1)</script>';
        $r = (new TestClient($this->app(['cloud_marketing' => $m])))->get('/cloud');
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; y', $r->body);
        $this->assertStringNotContainsString('<script>alert(1)', $r->body);
    }

    public function test_the_landing_page_stays_inside_the_one_query_budget(): void
    {
        $app = $this->app(['cloud_marketing' => $this->marketing()]);
        $db = $app->container->make(Database::class);
        $n = 0;
        \App\Tests\Support\QueryTap::on($db, function () use (&$n): void { $n++; });
        $res = $app->handle(new Request('GET', '/cloud', [], [], []));
        \App\Tests\Support\QueryTap::off($db);
        $this->assertSame(200, $res->status);
        $this->assertSame(1, $n, '/cloud runs exactly one content query');
    }

    /** The real copy sits in the gitignored config.marketing.php; when it is
     *  present, hold it to the owner's copy rules. Skipped on a clean clone. */
    public function test_local_copy_follows_the_house_rules_when_present(): void
    {
        $file = dirname(__DIR__) . '/config.marketing.php';
        if (!is_file($file)) $this->markTestSkipped('no local marketing config');
        $cfg = (array) require $file;
        $text = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString("\u{2014}", $text, 'no em dashes');
        $this->assertDoesNotMatchRegularExpression('/\b(leverage|synergy|empower|revolutionize|supercharge|delve|seamless|robust)\w*/i', $text);
        $this->assertDoesNotMatchRegularExpression('/automated tests?|1,100|database quer|\b0 ?KB|zero[- ]JavaScript|places left|restore drill/i', $text);
        $this->assertLessThanOrEqual(3, substr_count($text, 'MIT'), 'open source stays low-key');
        $this->assertGreaterThanOrEqual(3, substr_count($text, '30-day refund') + substr_count($text, '30-Day Refund'));
        $this->assertStringContainsString('"$9"', $text);
        $this->assertStringContainsString('"$19"', $text);
        $this->assertStringContainsString('SEO-Friendly', $text);
        $this->assertStringContainsString('backups', $text);
        $lp = $cfg['landing'] ?? [];
        $labels = array_column($lp['nav'] ?? [], 0);
        $this->assertSame(['How It Works', 'Features', 'Migration', 'Pricing', 'Compare', 'Blog', 'Docs', 'Status'], $labels);
        foreach ($lp['nav'] as [$label, $url]) {
            $this->assertMatchesRegularExpression('#^(\#|https://cloud\.kiption\.com/)#', $url, $label);
        }
        $small = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];
        $heads = [$lp['hero']['kicker'], $lp['hero']['h1'], $lp['why']['h2'], $lp['how']['label'], $lp['how']['h2'], $lp['offer']['heading'], $lp['pricing']['h2'], $lp['faq']['h2'], $cfg['close']['heading'], $cfg['cta_label']];
        foreach ($lp['how']['features'] as $f) $heads[] = $f['title'];
        foreach ($heads as $h) {
            foreach (preg_split('/\s+/', $h) as $i => $w) {
                if ($i > 0 && in_array(strtolower(trim($w, '.,')), $small, true)) continue;
                if ($w === 'eFiction') continue;
                $this->assertMatchesRegularExpression('/^[A-Z0-9]/', $w, $h);
            }
        }
    }
}
