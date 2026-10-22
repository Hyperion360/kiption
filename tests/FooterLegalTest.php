<?php // tests/FooterLegalTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use Kip\View;
use PHPUnit\Framework\TestCase;

// The operator legal line (the Attribution seam's sibling): a copyright
// holder and legal links captured from the boot config, rendered as one
// foot-legal paragraph after the foot-nav. Absent when unconfigured (the
// MIT default: nothing renders until an operator sets the keys), identical
// bytes for every visitor so cached pages stay stable.
final class FooterLegalTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-legal-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        \App\Attribution::reset();
        \App\FooterLegal::reset(); // no later suite inherits a configured footer
    }

    /** The LayoutShellTest harness verbatim: the bare layout rendered the way
     *  the app constructs its view, with the footer keys applied to the boot
     *  seams before the render. */
    private function renderLayout(array $configOverrides): string
    {
        $config = array_merge([
            'app_dir' => dirname(__DIR__) . '/app',
            'views' => dirname(__DIR__) . '/app/views',
            'powered_by' => true,
            'powered_by_url' => 'https://cloud.kiption.com',
        ], $configOverrides);
        \App\Attribution::init($config);
        \App\FooterLegal::init($config);
        $featuresDir = $config['features_dir']
            ?? (is_dir($config['app_dir'] . '/Features') ? $config['app_dir'] . '/Features' : '');
        return (new View($config['views'], $featuresDir))
            ->render('layout', ['content' => '', 'navFile' => '', 'title' => 'Layout']);
    }

    private function app(array $extra = []): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            ...$extra,
        ]);
    }

    private function client(?int $as = null, array $extra = []): TestClient
    {
        $client = new TestClient($this->app($extra));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** The foot-legal substring of a rendered body ('' when absent), for the
     *  byte-stability comparisons. */
    private function legal(string $body): string
    {
        preg_match('#<p class="foot-legal">.*?</p>#s', $body, $m);
        return $m[0] ?? '';
    }

    /** A configured holder renders with the build year, an https link with
     *  rel=noopener, and a holder containing markup renders escaped. */
    public function test_configured_copyright_renders_with_year_link_and_escaping(): void
    {
        $html = $this->renderLayout([
            'footer_copyright' => 'Hyper<i>ion360</i> Inc',
            'footer_copyright_url' => 'https://hyperion360.com/',
        ]);
        $this->assertStringContainsString('<p class="foot-legal">', $html);
        $this->assertStringContainsString('<span>© ' . date('Y') . ' <a href="https://hyperion360.com/" rel="noopener">Hyper&lt;i&gt;ion360&lt;/i&gt; Inc</a></span>', $html,
            'the year, the link, and the escaped holder, in one span');
        $this->assertStringNotContainsString('<i>ion360', $html, 'markup in the holder never reaches the page raw');
    }

    /** No url: the holder still renders, without an anchor around it. */
    public function test_copyright_without_url_renders_the_holder_as_plain_text(): void
    {
        $html = $this->renderLayout(['footer_copyright' => 'Hyperion360 Inc']);
        $this->assertStringContainsString('<span>© ' . date('Y') . ' Hyperion360 Inc</span>', $html);
        $this->assertStringNotContainsString('<a href="https://hyperion360.com/"', $html);
    }

    /** The MIT default: no keys, no paragraph (and only https and
     *  /-relative holders' urls ever link; anything else is plain text). */
    public function test_unconfigured_renders_no_foot_legal_paragraph(): void
    {
        $this->assertSame('', $this->legal($this->renderLayout([])), 'nothing renders until the operator sets the keys');
        // A non-https holder url renders the holder without an anchor.
        $this->assertStringContainsString('<span>© ' . date('Y') . ' Holder Inc</span>',
            $this->renderLayout(['footer_copyright' => 'Holder Inc', 'footer_copyright_url' => 'http://plain.test/']));
    }

    /** Legal links render in order; only https and /-relative urls anchor,
     *  javascript:, http:, and //host render as plain text (the nav stance). */
    public function test_footer_links_render_in_order_and_unsafe_urls_render_as_text(): void
    {
        $html = $this->renderLayout(['footer_links' => [
            ['label' => 'Terms', 'url' => '/page/view/terms'],
            ['label' => 'Privacy', 'url' => '/page/view/privacy'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['label' => 'Plain', 'url' => 'http://plain.test/'],
            ['label' => 'Evil', 'url' => '//evil.test/'],
        ]]);
        $terms = strpos($html, '<a href="/page/view/terms">Terms</a>');
        $privacy = strpos($html, '<a href="/page/view/privacy">Privacy</a>');
        $this->assertIsInt($terms);
        $this->assertIsInt($privacy);
        $this->assertLessThan($privacy, $terms, 'links render in the configured order');
        foreach (['javascript:', 'http://plain.test/', '//evil.test/'] as $url) {
            $this->assertStringNotContainsString('href="' . $url, $html, "{$url} never becomes an anchor");
        }
        $this->assertStringNotContainsString('<a>Bad</a>', $html);
        $this->assertStringNotContainsString('<a href="">Bad</a>', $html);
        $this->assertStringContainsString('Bad', $html, 'the unsafe link\'s label still renders, as text');
        $this->assertStringContainsString('Plain', $html);
        $this->assertStringContainsString('Evil', $html);
    }

    /** KIP_FOOTER_LINKS arrives as a JSON string; invalid JSON means no
     *  links, and entries without a string label+url pair drop out (the
     *  NavLinks::all stance). */
    public function test_footer_links_decode_defensively(): void
    {
        $this->assertStringContainsString('<a href="/page/view/terms">Terms</a>',
            $this->renderLayout(['footer_links' => '[{"label":"Terms","url":"/page/view/terms"}]']),
            'a JSON string config value decodes to links');
        $this->assertSame('', $this->legal($this->renderLayout(['footer_links' => 'not json'])),
            'invalid JSON means no links');
        $this->assertSame('', $this->legal($this->renderLayout(['footer_links' => '42'])),
            'a JSON scalar that is not a list of pairs means no links');
        $this->assertStringContainsString('<a href="/x">X</a>',
            $this->renderLayout(['footer_links' => '[[1,2],{"label":"X","url":"/x"},{"label":"NoUrl"}]']),
            'malformed entries drop; the one well-formed pair renders');
    }

    /** The cached-pages rule: the foot-legal bytes are identical for a guest
     *  and a logged-in member on the same page. */
    public function test_foot_legal_bytes_are_identical_for_guest_and_member(): void
    {
        $config = [
            'footer_copyright' => 'Hyperion360 Inc',
            'footer_copyright_url' => 'https://hyperion360.com/',
            'footer_links' => [
                ['label' => 'Terms', 'url' => '/page/view/terms'],
                ['label' => 'Privacy', 'url' => '/page/view/privacy'],
            ],
        ];
        \App\FooterLegal::init($config);
        $guest = $this->legal($this->client()->get('/')->body);
        $member = $this->legal($this->client($this->memberId)->get('/')->body);
        $this->assertStringContainsString('© ' . date('Y') . ' <a href="https://hyperion360.com/" rel="noopener">Hyperion360 Inc</a></span>', $guest);
        $this->assertSame($guest, $member, 'one legal line for guests and members: cached bytes stay stable');
    }
}
