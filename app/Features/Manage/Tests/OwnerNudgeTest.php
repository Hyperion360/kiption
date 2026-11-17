<?php // app/Features/Manage/Tests/OwnerNudgeTest.php
namespace App\Features\Manage\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Lang;
use Kip\{App, Database};
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The quiet line on /manage for an admin whose pen name is still "Owner"
 *  (what Kiption Cloud calls every new owner). */
#[Medium]
final class OwnerNudgeTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-nudge-');
        $this->root = sys_get_temp_dir() . '/kiption-nudge-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        Lang::reset();
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        Lang::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function admin(string $penname, string $slug): int
    {
        $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug . '@e.test', \App\Tests\Support\Hashes::of('password123'), $penname, 'admin', 1, date('c'), date('c'), $slug]);
        return (int) $this->db->lastInsertId();
    }

    private function client(int $as): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        Lang::useSiteText($this->root . '/site-text');
        $config = \App\Settings::apply([
            'env' => 'prod', 'views' => dirname(__DIR__, 3) . '/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'], 'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'ui_lang' => 'en', 'nav_file' => $this->root . '/nav.json', 'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 2), 'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'], 'site_text_dir' => $this->root . '/site-text',
        ]);
        return (new TestClient(new App($config)))->actingAs($as);
    }

    public function test_admin_still_called_owner_sees_the_nudge_and_it_goes_after_a_rename(): void
    {
        $id = $this->admin('Owner', 'owner');
        $body = $this->client($id)->get('/manage')->body;
        $this->assertStringContainsString('You are still called &quot;Owner&quot;.', $body);
        $this->assertMatchesRegularExpression('#<a href="/account/settings\#penname">Pick the name readers will see\.</a>#', $body);
        // Order: h1, then the nudge, then the Post a story button, then the screens.
        $this->assertLessThan(strpos($body, 'Pick the name'), strpos($body, '<h1>'));
        $this->assertLessThan(strpos($body, 'href="/story/new"'), strpos($body, 'Pick the name'));

        $this->db->query('UPDATE users SET penname = ? WHERE id = ?', ['Scribble', $id]);
        $this->assertStringNotContainsString('Pick the name readers will see', $this->client($id)->get('/manage')->body);
    }

    public function test_an_admin_with_any_other_name_never_sees_it(): void
    {
        $id = $this->admin('Owner Two', 'owner-two');
        $this->assertStringNotContainsString('Pick the name readers will see', $this->client($id)->get('/manage')->body);
        $id = $this->admin('owner', 'owner-lower');
        $this->assertStringNotContainsString('Pick the name readers will see', $this->client($id)->get('/manage')->body);
    }
}
