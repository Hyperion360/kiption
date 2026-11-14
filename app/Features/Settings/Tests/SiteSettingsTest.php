<?php // app/Features/Settings/Tests/SiteSettingsTest.php
namespace App\Features\Settings\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The owner switches added to /settings: tagline, posting pause, word limits,
 *  date style, reviews, co-authors, series, default order, public profiles and
 *  uploads. Each one is pinned by its effect on a public page, not only by the
 *  stored row. */
final class SiteSettingsTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private int $adminId = 0;
    private int $memberId = 0;
    private int $authorId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-sitesettings-') . '.sqlite';
        $this->root = sys_get_temp_dir() . '/kiption-sitesettings-' . uniqid('', true);
        mkdir($this->root . '/app', 0775, true);
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $this->db->query("INSERT INTO users (email, password_hash, penname, role, is_admin, email_verified_at, approved_at, profile_slug) VALUES ('ssadmin@e.test', ?, 'ssadmin', 'admin', 1, ?, ?, 'ssadmin')", [$hash, date('c'), date('c')]);
        $this->adminId = (int) $this->db->lastInsertId();
        $this->db->query("INSERT INTO users (email, password_hash, penname, email_verified_at, approved_at, profile_slug) VALUES ('ssmember@e.test', ?, 'ssmember', ?, ?, 'ssmember')", [$hash, date('c'), date('c')]);
        $this->memberId = (int) $this->db->lastInsertId();
        $this->authorId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
    }

    protected function tearDown(): void
    {
        \App\Features::reset();
        \App\SiteName::reset();
        unset($this->db);
        exec('rm -rf ' . escapeshellarg($this->root));
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function set(array $pairs): void
    {
        \App\Features::init($this->db, []);
        foreach ($pairs as $k => $v) \App\Settings::put($k, (string) $v);
        // Guests are served from the page cache; a real save purges it.
        @unlink($this->root . '/app/cache.sqlite');
        exec('rm -rf ' . escapeshellarg($this->root . '/static'));
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        \App\Features::init(new Database('sqlite:' . $this->path), []);
        $config = \App\Settings::apply($extra + [
            'env' => 'prod', 'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path], 'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->root . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'registration_mode' => 'verify', 'validation_required' => false, 'items_per_page' => 20,
            'nav_file' => $this->root . '/nav.json', 'app_dir' => $this->root . '/app',
            'features_dir' => dirname(__DIR__, 4) . '/app/Features',
            'static_cache' => ['dir' => $this->root . '/static'],
            'cache_db' => ['dsn' => 'sqlite:' . $this->root . '/app/cache.sqlite'],
        ]);
        $client = new TestClient(new App($config));
        return $as === null ? $client : $client->actingAs($as);
    }

    private function rows(string $table): int
    {
        return (int) $this->db->one("SELECT COUNT(*) c FROM {$table}")['c'];
    }

    // --- the form itself -------------------------------------------------

    public function test_the_form_saves_every_new_setting_and_shows_it_again(): void
    {
        $res = $this->client($this->adminId)->postWithToken('/settings/save', [
            'site_name' => 'Lantern', 'registration_mode' => 'open', 'items_per_page' => '20', 'skin' => 'default',
            'slogan' => 'Stories by lamplight', 'min_words' => '10', 'max_words' => '500',
            'date_format' => 'iso', 'time_format' => '24h', 'default_sort' => 'alpha',
            'submissions_open' => '1', 'reviews_on' => '1', 'series_on' => '1', 'coauthors_on' => '1',
        ]);
        $this->assertSame(302, $res->status);
        $board = (string) $this->client($this->adminId)->get('/settings')->body;
        foreach (['Stories by lamplight', 'value="500"', 'Basics', 'Stories', 'Reviews', 'Members', 'Look'] as $needle) {
            $this->assertStringContainsString($needle, $board);
        }
        $this->assertSame('1', \App\Settings::get('submissions_open'));
        // Unchecked boxes never post: they save as off.
        $this->assertSame('0', \App\Settings::get('guest_reviews'));
        $this->assertSame('0', \App\Settings::get('uploads_on'));
        $this->assertSame('iso', \App\Settings::get('date_format'));
    }

    public function test_invalid_values_are_refused(): void
    {
        $admin = $this->client($this->adminId);
        $base = ['site_name' => 'X', 'registration_mode' => 'open', 'items_per_page' => '20'];
        foreach ([['date_format' => 'bogus'], ['time_format' => '99h'], ['default_sort' => 'random'],
                  ['min_words' => '-3'], ['max_words' => 'lots'], ['slogan' => str_repeat('x', 121)],
                  ['min_words' => '900', 'max_words' => '100']] as $bad) {
            $res = $admin->postWithToken('/settings/save', $bad + $base);
            $this->assertSame(422, $res->status, json_encode($bad));
        }
        // A refused value never lands (the bad key itself is not stored).
        $row = $this->db->one("SELECT value FROM settings WHERE key = 'date_format'");
        $this->assertNotSame('bogus', $row['value'] ?? null);
    }

    public function test_the_settings_copy_never_mentions_files_or_commands(): void
    {
        $board = (string) $this->client($this->adminId)->get('/settings')->body
               . (string) $this->client($this->adminId)->get('/features')->body;
        foreach (['config.php', 'app/skins', 'SKINS.md', 'pages:build', 'release:due', 'bin/kip'] as $word) {
            $this->assertStringNotContainsString($word, $board);
        }
    }

    public function test_a_save_purges_the_page_cache(): void
    {
        $cacheDir = $this->root . '/static';
        mkdir($cacheDir, 0775, true);
        $static = new \App\StaticCache\Cache($cacheDir);
        $req = new \Kip\Http\Request('GET', '/', [], [], []);
        $static->maybeStore($req, new \Kip\Http\Response('cached', 200));
        $this->assertNotNull($static->serve($req));
        $this->client($this->adminId)->postWithToken('/settings/save', ['site_name' => 'Y', 'registration_mode' => 'open', 'items_per_page' => '20', 'slogan' => 'New line']);
        $this->assertNull($static->serve($req));
    }

    // --- C2.1 tagline ----------------------------------------------------

    public function test_the_tagline_shows_in_the_header_of_every_skin_and_is_escaped(): void
    {
        $this->assertStringNotContainsString('class="tagline"', (string) $this->client()->get('/')->body);
        foreach (['default', 'classic', 'manuscript'] as $skin) {
            $this->set(['slogan' => 'Tea & <b>ink</b>', 'skin' => $skin]);
            $html = (string) $this->client()->get('/')->body;
            $this->assertStringContainsString('<span class="tagline">Tea &amp; &lt;b&gt;ink&lt;/b&gt;</span>', $html, $skin);
        }
        $this->set(['slogan' => '']);
        $this->assertStringNotContainsString('class="tagline"', (string) $this->client()->get('/')->body);
    }

    // --- C2.2 posting pause ----------------------------------------------

    public function test_a_paused_archive_refuses_new_stories_and_chapters_but_not_owners(): void
    {
        $this->set(['submissions_open' => '0']);
        $member = $this->client($this->authorId);
        $before = $this->rows('stories');
        $page = $member->get('/story/new');
        $this->assertSame(403, $page->status);
        $this->assertStringContainsString('Posting Is Paused', (string) $page->body);
        $this->assertSame(403, $member->postWithToken('/story/create', ['title' => 'Nope', 'rating_id' => '1'])->status);
        $this->assertSame($before, $this->rows('stories'));
        $chapters = $this->rows('chapters');
        $this->assertSame(403, $member->get('/chapter/new/the-rabbit-hole')->status);
        $this->assertSame(403, $member->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'N', 'content' => 'Some words here.'])->status);
        $this->assertSame($chapters, $this->rows('chapters'));
        // Owners can still post.
        $this->assertSame(200, $this->client($this->adminId)->get('/story/new')->status);
        // Editing existing work is untouched.
        $this->assertSame(200, $member->get('/chapter/edit/the-rabbit-hole/1')->status);
        $this->set(['submissions_open' => '1']);
        $this->assertSame(200, $this->client($this->authorId)->get('/story/new')->status);
    }

    // --- C2.3 word limits --------------------------------------------------

    public function test_chapter_word_limits_are_enforced_with_the_count_in_the_message(): void
    {
        $author = fn () => $this->client($this->authorId);
        $this->set(['min_words' => '5', 'max_words' => '8']);
        $short = $author()->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'S', 'content' => 'Two words.']);
        $this->assertSame(422, $short->status);
        $this->assertStringContainsString('2 words', (string) $short->body);
        $this->assertStringContainsString('at least 5', (string) $short->body);
        $long = $author()->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'L', 'content' => 'one two three four five six seven eight nine ten']);
        $this->assertSame(422, $long->status);
        $this->assertStringContainsString('10 words', (string) $long->body);
        $this->assertStringContainsString('no more than 8', (string) $long->body);
        $this->assertSame(3, $this->rows('chapters') - 1, 'nothing saved'); // 3 + 1 seeded
        $this->assertSame(302, $author()->postWithToken('/chapter/create/the-rabbit-hole', ['title' => 'OK', 'content' => 'one two three four five six'])->status);
        // Editing is held to the same limits.
        $edit = $author()->postWithToken('/chapter/update/the-rabbit-hole/1', ['title' => 'Down', 'content' => 'tiny']);
        $this->assertSame(422, $edit->status);
        // 0 means no limit.
        $this->set(['min_words' => '0', 'max_words' => '0']);
        $this->assertSame(302, $author()->postWithToken('/chapter/update/the-rabbit-hole/1', ['title' => 'Down', 'content' => 'tiny'])->status);
    }

    // --- C2.4 dates ------------------------------------------------------

    public function test_dates_follow_the_owner_style_everywhere(): void
    {
        $this->assertSame('Sep 10, 2026', \App\Dates::format('2026-09-10T09:00:00Z'));
        $this->assertSame('Sep 10', \App\Dates::format('2026-09-10T09:00:00Z', false));
        $this->assertSame('', \App\Dates::format(null));
        $this->assertSame('', \App\Dates::format('not a date'));
        foreach (['long' => 'September 10, 2026', 'dmy' => '10 Sep 2026', 'iso' => '2026-09-10'] as $style => $shown) {
            $this->set(['date_format' => $style]);
            $this->assertSame($shown, \App\Dates::format('2026-09-10T09:00:00Z'));
            // A real page: the story's "Updated" line and the chapter list use the helper.
            $this->assertStringContainsString($style === 'iso' ? 'Updated 2026-09-10' : 'Updated ' . \App\Dates::format('2026-09-10T09:00:00Z', false), (string) $this->client()->get('/story/view/the-rabbit-hole')->body, $style);
        }
        $this->set(['time_format' => '24h']);
        $this->assertMatchesRegularExpression('/ \d{2}:\d{2}$/', \App\Dates::format('2026-09-10T14:05:00Z', true, true));
        $this->set(['time_format' => '12h']);
        $this->assertMatchesRegularExpression('/ \d{1,2}:\d{2} (AM|PM)$/', \App\Dates::format('2026-09-10T14:05:00Z', true, true));
    }

    public function test_no_view_prints_a_date_without_the_helper(): void
    {
        $root = dirname(__DIR__, 3);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $bad = [];
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (!str_ends_with($p, '.php') || !preg_match('#/views/|/skins/#', $p) || str_contains($p, '/Tests/')) continue;
            foreach (file($p) as $n => $line) {
                if (preg_match("/\\bdate\\('[A-Za-z ,]*[MFjdnmy]/", $line) && !str_contains($line, "date('Y')")) $bad[] = "{$p}:" . ($n + 1);
                if (preg_match('/substr\\([^)]*_at[^)]*, 0, 10\\)/', $line)) $bad[] = "{$p}:" . ($n + 1);
            }
        }
        $this->assertSame([], $bad, 'route these through App\Dates::format');
    }

    // --- C2.5 reviews ----------------------------------------------------

    public function test_reviews_can_be_switched_off(): void
    {
        $this->assertStringContainsString('story-reviews', (string) $this->client()->get('/story/view/the-rabbit-hole')->body);
        $this->set(['reviews_on' => '0']);
        $this->assertStringNotContainsString('story-reviews', (string) $this->client()->get('/story/view/the-rabbit-hole')->body);
        $before = $this->rows('reviews');
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Lovely'])->status);
        $this->assertSame(404, $this->client()->get('/review/chapter/the-rabbit-hole/1')->status);
        $this->assertSame(404, $this->client($this->memberId)->postWithToken('/review/addchapter/the-rabbit-hole/1', ['body' => 'Hi'])->status);
        $this->assertSame($before, $this->rows('reviews'));
    }

    public function test_guest_reviews_can_require_sign_in(): void
    {
        $this->set(['guest_reviews' => '0']);
        $guest = $this->client();
        $html = (string) $guest->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringNotContainsString('name="guest_name"', $html);
        $this->assertStringContainsString('Sign in to leave a review', $html);
        $this->assertSame(403, $guest->postWithToken('/review/add/the-rabbit-hole', ['guest_name' => 'G', 'body' => 'Hi'])->status);
        $this->assertSame(0, $this->rows('reviews'));
        $this->assertSame(302, $this->client($this->memberId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Member words'])->status);
        $this->assertSame(1, $this->rows('reviews'));
        $this->set(['guest_reviews' => '1']);
        $this->assertStringContainsString('name="guest_name"', (string) $this->client()->get('/story/view/the-rabbit-hole')->body);
    }

    public function test_a_rating_alone_counts_only_when_the_owner_allows_it(): void
    {
        $member = fn () => $this->client($this->memberId);
        $this->assertSame(422, $member()->postWithToken('/review/add/the-rabbit-hole', ['body' => '', 'rating' => '7'])->status);
        $this->set(['rating_only' => '1']);
        $this->assertStringNotContainsString('required maxlength="5000"></textarea>', (string) $member()->get('/story/view/the-rabbit-hole')->body);
        $this->assertSame(302, $member()->postWithToken('/review/add/the-rabbit-hole', ['body' => '', 'rating' => '7'])->status);
        $this->assertSame(7, (int) $this->db->one('SELECT rating FROM reviews')['rating']);
        // No words and no rating is still nothing.
        $this->assertSame(422, $member()->postWithToken('/review/add/after-hours', ['body' => '', 'rating' => ''])->status);
    }

    // --- C2.6 co-authors and series --------------------------------------

    public function test_coauthors_can_be_switched_off(): void
    {
        $this->set(['coauthors_on' => '0']);
        $before = $this->rows('coauthors');
        $this->assertSame(404, $this->client($this->authorId)->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'ssmember'])->status);
        $this->assertSame($before, $this->rows('coauthors'));
        $this->assertStringNotContainsString('/coauthor/add/', (string) $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body);
        $this->set(['coauthors_on' => '1']);
        $this->assertStringContainsString('/coauthor/add/', (string) $this->client($this->authorId)->get('/story/edit/the-rabbit-hole')->body);
        $this->assertSame(302, $this->client($this->authorId)->postWithToken('/coauthor/add/the-rabbit-hole', ['penname' => 'ssmember'])->status);
    }

    public function test_series_can_be_switched_off(): void
    {
        $this->assertSame(200, $this->client()->get('/series')->status);
        $this->assertStringContainsString('href="/series"', (string) $this->client()->get('/')->body);
        $this->set(['series_on' => '0']);
        $this->assertSame(404, $this->client()->get('/series')->status);
        $this->assertSame(404, $this->client()->get('/series/view/down-the-rabbit-hole')->status);
        $this->assertSame(404, $this->client($this->authorId)->get('/series/new')->status);
        $story = (string) $this->client()->get('/story/view/the-rabbit-hole')->body;
        $this->assertStringNotContainsString('/series/view/down-the-rabbit-hole', $story);
        foreach (['default', 'classic', 'manuscript'] as $skin) {
            $this->set(['skin' => $skin]);
            $this->assertStringNotContainsString('class="nav-wide" href="/series"', (string) $this->client()->get('/')->body, $skin);
        }
    }

    // --- C2.7 sort and profiles ----------------------------------------

    public function test_default_sort_applies_when_the_visitor_has_not_chosen(): void
    {
        $this->db->query("UPDATE stories SET updated_at = '2026-10-30T09:00:00Z' WHERE slug = 'the-rabbit-hole'");
        $titles = static function (string $html): array {
            preg_match_all('#The Rabbit Hole|After Hours#', $html, $m);
            return array_values(array_unique($m[0]));
        };
        $this->assertSame(['The Rabbit Hole', 'After Hours'], $titles((string) $this->client()->get('/user/stories/demo-author')->body));
        $this->set(['default_sort' => 'alpha']);
        $this->assertSame(['After Hours', 'The Rabbit Hole'], $titles((string) $this->client()->get('/user/stories/demo-author')->body));
        // An explicit choice still wins.
        $this->assertSame(['The Rabbit Hole', 'After Hours'], $titles((string) $this->client()->get('/user/stories/demo-author', ['sort' => 'recent'])->body));
    }

    public function test_profiles_can_be_limited_to_signed_in_members(): void
    {
        $this->assertSame(200, $this->client()->get('/user/view/demo-author')->status);
        $this->set(['profiles_public' => '0']);
        foreach (['/user/view/demo-author', '/user/stories/demo-author', '/user/favorites/demo-author'] as $url) {
            $res = $this->client()->get($url);
            $this->assertSame(302, $res->status, $url);
            $this->assertSame('/auth/login', $res->headers['Location'] ?? null, $url);
            $this->assertSame(200, $this->client($this->memberId)->get($url)->status, $url);
        }
    }

    // --- C2.8 uploads ----------------------------------------------------

    public function test_uploads_can_be_switched_off(): void
    {
        $this->assertStringContainsString('/images/upload', (string) $this->client($this->adminId)->get('/images')->body);
        $this->assertStringContainsString('/account/avatar', (string) $this->client($this->memberId)->get('/account/settings')->body);
        $this->set(['uploads_on' => '0']);
        $this->assertStringNotContainsString('/images/upload', (string) $this->client($this->adminId)->get('/images')->body);
        $this->assertStringNotContainsString('/account/avatar', (string) $this->client($this->memberId)->get('/account/settings')->body);
        $this->assertSame(403, $this->client($this->adminId)->postWithToken('/images/upload', [])->status);
        $this->assertSame(403, $this->client($this->memberId)->postWithToken('/account/avatar', [])->status);
    }

    public function test_the_sitemap_leaves_out_series_when_series_is_off(): void
    {
        $dir = $this->root . '/sm1';
        \App\Seo\Sitemap::writeAll($this->db, $dir, 'https://archive.example', []);
        $this->assertFileExists($dir . '/sitemap-series.xml');
        $this->set(['series_on' => '0']);
        $dir = $this->root . '/sm2';
        \App\Seo\Sitemap::writeAll($this->db, $dir, 'https://archive.example', []);
        $this->assertFileDoesNotExist($dir . '/sitemap-series.xml');
        $this->assertStringNotContainsString('sitemap-series', (string) file_get_contents($dir . '/sitemap.xml'));
    }

    public function test_members_only_profiles_leave_the_sitemap_and_the_directory(): void
    {
        $this->assertSame(200, $this->client()->get('/browse/authors')->status);
        $this->set(['profiles_public' => '0']);
        $dir = $this->root . '/sm3';
        \App\Seo\Sitemap::writeAll($this->db, $dir, 'https://archive.example', []);
        $this->assertFileDoesNotExist($dir . '/sitemap-authors.xml');
        $res = $this->client()->get('/browse/authors');
        $this->assertSame(302, $res->status);
        $this->assertSame('/auth/login', $res->headers['Location'] ?? null);
        $this->assertSame(200, $this->client($this->memberId)->get('/browse/authors')->status);
    }
}
