<?php // tests/CloudPageTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The simplified /cloud tier every install ships: a seeded, editable Page
 *  row with short default copy and one link to a configurable URL. It carries
 *  no plan names, prices, or limits. Admins edit, hide, or delete it like any
 *  page, and a deleted row is never seeded again (the migration runs once).
 *  The full flagship landing is CloudLandingTest's business. */
final class CloudPageTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-landing-') . '.sqlite';
        $db = new Database('sqlite:' . $this->path);
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
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
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-landing.log', 'from' => 'noreply@localhost'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ], $extra));
        return new TestClient($app);
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->path);
    }

    public function test_a_fresh_install_serves_the_short_default_page_with_the_default_link(): void
    {
        $r = $this->client()->get('/cloud');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Want Kiption hosted for you? We run it, back it up, and keep it updated.', $r->body);
        $this->assertStringContainsString('href="https://kiption.com/cloud"', $r->body);
        $this->assertStringContainsString('<h1>Kiption Cloud</h1>', $r->body);
    }

    public function test_the_default_page_names_no_plans_prices_or_limits(): void
    {
        $r = $this->client()->get('/cloud');
        preg_match('#<article.*?</article>#s', $r->body, $a);
        $this->assertNotEmpty($a);
        $this->assertDoesNotMatchRegularExpression('/\$\d|Standard|Pro\b|\bGB\b|refund|plan-card|plans-band|cloud\.kiption\.com/i', $a[0]);
    }

    public function test_the_link_target_is_configurable(): void
    {
        $r = $this->client(['cloud_url' => 'https://host.example/hosted'])->get('/cloud');
        $this->assertStringContainsString('href="https://host.example/hosted"', $r->body);
        $this->assertStringNotContainsString('href="https://kiption.com/cloud"', $r->body);
    }

    public function test_an_empty_link_setting_drops_the_button_but_keeps_the_text(): void
    {
        $r = $this->client(['cloud_url' => ''])->get('/cloud');
        $this->assertStringContainsString('We run it, back it up, and keep it updated.', $r->body);
        $this->assertStringNotContainsString('cloud-link', $r->body);
    }

    public function test_an_admin_edit_changes_the_text(): void
    {
        $this->db()->query("UPDATE pages SET title = 'Hosting', body = 'Our own words.' WHERE slug = 'cloud'");
        $r = $this->client()->get('/cloud');
        $this->assertStringContainsString('<h1>Hosting</h1>', $r->body);
        $this->assertStringContainsString('Our own words.', $r->body);
        $this->assertStringNotContainsString('Want Kiption hosted for you?', $r->body);
    }

    public function test_hiding_from_search_engines_sets_noindex(): void
    {
        $this->db()->query("UPDATE pages SET noindex = 1 WHERE slug = 'cloud'");
        $r = $this->client()->get('/cloud');
        $this->assertSame(200, $r->status);
        $this->assertSame('noindex', $r->headers['X-Robots-Tag'] ?? '');
    }

    public function test_the_page_is_not_protected_so_an_admin_can_delete_it(): void
    {
        $this->assertNotContains('cloud', \App\Features\Page\PageController::PROTECTED);
    }

    public function test_a_deleted_page_is_gone_and_a_later_migrate_never_brings_it_back(): void
    {
        $this->db()->query("DELETE FROM pages WHERE slug = 'cloud'");
        $this->assertSame(404, $this->client()->get('/cloud')->status);
        (new Migrator($this->db(), \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->assertNull($this->db()->one("SELECT slug FROM pages WHERE slug = 'cloud'"));
        $this->assertSame(404, $this->client()->get('/cloud')->status);
    }

    public function test_an_existing_operator_page_survives_the_seed(): void
    {
        $db = new Database('sqlite:' . $this->path);
        $db->query("UPDATE pages SET body = 'Mine.' WHERE slug = 'cloud'");
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->assertSame('Mine.', $db->one("SELECT body FROM pages WHERE slug = 'cloud'")['body']);
    }

    public function test_plans_in_the_config_without_a_landing_do_not_reach_the_page(): void
    {
        $m = ['cta_label' => 'Start', 'cta_url' => 'https://cloud.example/start',
              'plans' => [['name' => 'Zeta', 'price' => '$7', 'period' => '/ month']]];
        $r = $this->client(['cloud_marketing' => $m])->get('/cloud');
        $this->assertStringNotContainsString('Zeta', $r->body);
        $this->assertStringContainsString('We run it, back it up', $r->body);
    }

    public function test_page_view_spelling_redirects_to_the_top_path(): void
    {
        $r = $this->client()->get('/page/view/cloud');
        $this->assertSame(301, $r->status);
        $this->assertSame('/cloud', $r->headers['Location'] ?? '');
    }
}
