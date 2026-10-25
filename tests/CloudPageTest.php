<?php // tests/CloudPageTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The /cloud landing template: the title's colon split (kicker + display
 *  heading), the body's first paragraph lifted as the lede, and the
 *  cloud_marketing config bands (CTA, plan cards, offer callout, close).
 *  Without the config the page degrades to hero + body with no bands. */
final class CloudPageTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-cloudpg-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        $db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)',
            ['cloud', 'Kiption Cloud: your archive, hosted and maintained',
             "Kiption Cloud is the hosted way to run a fiction archive.\n\nThe dated record behind the offer.\n\nSecond closing paragraph."]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    /** @param array<string, mixed> $extra */
    private function client(array $extra = []): TestClient
    {
        $app = new App(array_merge([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-cloudpg.log', 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $extra));
        return new TestClient($app);
    }

    private function marketing(): array
    {
        return [
            'cta_label' => 'Start your archive', 'cta_url' => 'https://cloud.example/start',
            'proof' => 'This archive runs on the same build.',
            'plans_label' => 'The plans',
            'plans' => [
                ['name' => 'Standard', 'blurb' => 'For an archive finding a new home.', 'price' => '$9', 'period' => '/ month',
                 'alt' => 'or $90 a year', 'rows' => ['2 GB storage'], 'cta' => 'Start Standard', 'url' => 'https://cloud.example/start?plan=standard'],
                ['name' => 'Pro', 'blurb' => 'For a community.', 'price' => '$19', 'period' => '/ month',
                 'rows' => ['10 GB storage'], 'cta' => 'Start Pro', 'url' => 'https://cloud.example/start?plan=pro',
                 'featured' => true, 'badge' => 'Recommended', 'note' => 'Migration included.'],
            ],
            'offer' => 'Every yearly plan includes the migration. [Read the terms](https://archive.example/cloud)',
            'close' => ['heading' => 'Start your archive', 'body' => 'Pick a plan, monthly or yearly.'],
        ];
    }

    public function test_cloud_renders_kicker_lede_and_body_without_config(): void
    {
        $r = $this->client()->get('/cloud');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('cloud-kicker', $r->body, 'kicker renders from the colon split');
        $this->assertStringContainsString('cloud-lede', $r->body, 'the first paragraph lifts into the lede');
        $this->assertStringContainsString('Kiption Cloud is the hosted way', $r->body, 'lede text present');
        $this->assertStringContainsString('cloud-body', $r->body, 'the remaining body renders below');
        $this->assertStringContainsString('The dated record behind the offer.', $r->body, 'body keeps its paragraphs');
        $this->assertStringNotContainsString('plans-band', $r->body, 'no plans band without the config');
        $this->assertStringNotContainsString('cta-primary', $r->body, 'no CTA button without the config');
        $this->assertStringNotContainsString('close-band', $r->body, 'no close band without the config');
    }

    public function test_cloud_renders_the_marketing_bands_from_config(): void
    {
        $r = $this->client(['cloud_marketing' => $this->marketing()])->get('/cloud');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('href="https://cloud.example/start"', $r->body, 'hero CTA link');
        $this->assertStringContainsString('This archive runs on the same build.', $r->body, 'proof line');
        $this->assertStringContainsString('id="plans"', $r->body, 'plans band anchors for the See-the-plans link');
        $this->assertStringContainsString('Standard', $r->body);
        $this->assertStringContainsString('href="https://cloud.example/start?plan=pro"', $r->body, 'card CTA carries its own URL');
        $this->assertStringContainsString('Recommended', $r->body, 'badge renders');
        $this->assertStringContainsString('Migration included.', $r->body, 'card note renders');
        $this->assertStringContainsString('Every yearly plan includes the migration.', $r->body, 'offer callout renders');
        $this->assertStringContainsString('href="https://archive.example/cloud"', $r->body, 'offer markdown link renders as a link');
        $this->assertStringContainsString('close-band', $r->body, 'close band renders');
        $this->assertStringContainsString('Pick a plan, monthly or yearly.', $r->body, 'close body renders');
    }

    public function test_page_view_spelling_redirects_to_the_top_path(): void
    {
        $r = $this->client(['cloud_marketing' => $this->marketing()])->get('/page/view/cloud');
        $this->assertSame(301, $r->status, 'the mapped slug redirects to its top-level spelling');
        $this->assertSame('/cloud', $r->headers['Location'] ?? '', 'the redirect names the canonical path');
    }
}
