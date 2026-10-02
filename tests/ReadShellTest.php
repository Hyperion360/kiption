<?php // tests/ReadShellTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

// C8 (frames M2/T1/D1 + focus D2): the chapter reader shell. Sticky reader
// header with the story-span progressbar, icon-over-caption bottom control
// bar, :target sheets for contents/bookmarks and text settings (the size
// group is the comp's slider row), the end-of-chapter block with the
// next-chapter anatomy, and the focus variant. The h-entry microformats
// block and the read beacon are pinned byte-exact by MicroformatsTest; this
// file pins the shell around them. No scripting in the shell itself: links,
// forms, native inputs, and :target only; the deferred enhancement layer is
// pinned in LayoutShellTest and the keys contract in AppJsContractTest.
final class ReadShellTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-rshell-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $client = new TestClient(new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-rshell-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $as === null ? $client : $client->actingAs($as);
    }

    /** Seeded story: 600 words, ch1 100 / ch2 200 / ch3 300, so chapter 2
     *  spans 17%..50% of the whole work (100/600 and 300/600 rounded). */
    public function test_reader_header_carries_back_link_titles_percent_and_progressbar(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<header class="reader-head">', $body);
        $this->assertStringContainsString('<a class="reader-back" href="/story/view/the-rabbit-hole" aria-label="Back to story">←</a>', $body);
        $this->assertStringContainsString('<span class="rt-story">The Rabbit Hole</span>', $body);
        // line 2: "ROMAN · title" when the chapter carries a title
        $this->assertStringContainsString('<span class="rt-chapter">II · Through</span>', $body);
        // the wiring attribute is the position module's marker (AppJsContractTest)
        $this->assertStringContainsString('<span class="reader-pct" data-js="reader-pct">50%</span>', $body);
        // the story-span progressbar: static --p-end fallback + scroll-driven
        // --p-start..--p-end enhancement, valuenow at the chapter's end
        $this->assertStringContainsString('role="progressbar"', $body);
        $this->assertStringContainsString('aria-valuenow="50"', $body);
        $this->assertStringContainsString('aria-valuemin="0"', $body);
        $this->assertStringContainsString('aria-valuemax="100"', $body);
        $this->assertStringContainsString('style="--p-start:17%;--p-end:50%"', $body);
        // the first chapter starts at zero
        $first = $this->client()->get('/story/read/the-rabbit-hole/1')->body;
        $this->assertStringContainsString('style="--p-start:0%;--p-end:17%"', $first);
    }

    public function test_bottom_control_bar_renders_contents_stepper_text_and_bookmark(): void
    {
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<nav class="reader-bar"', $body);
        $this->assertStringContainsString('href="#contents"', $body);
        $this->assertStringContainsString('href="#text"', $body);
        // icon-over-caption items; the ‹ › stepper arrows are gone (prev/next
        // ride the chapter-nav block above the bar)
        $this->assertStringContainsString('<span class="bar-chapter">', $body);
        $this->assertStringContainsString('<span class="bar-count">2 / 3</span>', $body);
        $this->assertStringContainsString('<span class="bar-caption">Chapter</span>', $body);
        $this->assertStringContainsString('<span class="bar-caption">Contents</span>', $body);
        $this->assertStringContainsString('<span class="bar-caption">Text</span>', $body);
        $this->assertStringNotContainsString('‹', $body);
        $this->assertStringNotContainsString('›', $body);
        // the member bookmark form (the flat-router URL spelling)
        $this->assertStringContainsString('action="/reader/bookmarkadd/the-rabbit-hole/2"', $body);
        $this->assertStringContainsString('name="_token"', $body);
        // a member who saved this chapter sees the remove form, filled ribbon,
        // and the Saved caption instead
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'x']);
        $saved = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('action="/reader/bookmarkremove/the-rabbit-hole/2"', $saved);
        $this->assertStringContainsString('<button type="submit" class="is-saved" aria-label="Saved">', $saved);
        $this->assertStringContainsString('<span class="bar-caption">Saved</span>', $saved);
        $this->assertStringNotContainsString('action="/reader/bookmarkadd/the-rabbit-hole/2"', $saved);
    }

    public function test_guest_bar_bookmark_is_a_login_link_not_a_form(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<a href="/auth/login" aria-label="Bookmark">', $body);
        $this->assertStringContainsString('<span class="bar-caption">Bookmark</span>', $body);
        $this->assertStringNotContainsString('bookmarkadd', $body);
        $this->assertStringNotContainsString('bookmarkremove', $body);
    }

    public function test_contents_sheet_is_radio_tabbed_for_members_and_plain_for_guests(): void
    {
        $guest = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<div id="contents" class="sheet" role="dialog"', $guest);
        $this->assertStringContainsString('aria-label="Contents"', $guest);
        $this->assertStringNotContainsString('name="ctab"', $guest, 'no tabs without a bookmarks pane behind them');
        // the titled chapter rows, current one marked
        $this->assertStringContainsString('<span class="ch-title">Through</span>', $guest);
        $this->assertStringContainsString('aria-current="page"', $guest);

        $member = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('aria-label="Contents and bookmarks"', $member);
        $this->assertStringContainsString('<input type="radio" name="ctab" id="ctab-contents" class="ctab-radio" checked>', $member);
        $this->assertStringContainsString('<input type="radio" name="ctab" id="ctab-bookmarks" class="ctab-radio">', $member);
        // a planted bookmark renders its escaped note plus the remove form
        $this->client($this->memberId)->postWithToken('/reader/bookmarkadd/the-rabbit-hole/2', ['note' => 'stop <here>']);
        $saved = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('stop &lt;here&gt;', $saved);
        $this->assertStringContainsString('action="/reader/bookmarkremove/the-rabbit-hole/2"', $saved);
    }

    public function test_text_sheet_posts_settings_with_current_values_checked(): void
    {
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringContainsString('<div id="text" class="sheet" role="dialog"', $body);
        $this->assertStringContainsString('<form method="post" action="/reader/settings" class="text-settings" data-js="settings-form" data-js-module="prefs">', $body);
        $this->assertStringContainsString('<input type="hidden" name="return_to" value="/story/read/the-rabbit-hole/2">', $body);
        $this->assertStringContainsString('name="_token"', $body, 'members carry the CSRF token');
        // seven fieldsets: size, typeface, spacing, paragraphs, theme, width, mode
        foreach (['name="size"', 'name="typeface"', 'name="spacing"', 'name="paragraphs"',
                  'name="theme"', 'name="width"', 'name="mode"'] as $radio) {
            $this->assertStringContainsString($radio, $body, "the {$radio} group renders");
        }
        $this->assertSame(7, substr_count($body, '<fieldset>'));
        // the size group is the comp's slider row now: native range 16..24 with
        // the current px readout on the legend line
        $this->assertStringContainsString('<input type="range" name="size" min="16" max="24" step="1" value="19"', $body,
            'cookieless default is 19px');
        $this->assertStringContainsString('aria-label="Text size"', $body);
        $this->assertStringContainsString('<span class="size-readout">19 px</span>', $body);
        $this->assertStringContainsString('<input type="radio" name="theme" value="auto" checked data-js-pref="theme">', $body);
    }

    public function test_end_of_chapter_block_with_kudos_review_and_next(): void
    {
        $body = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        // the caption names the chapter when it carries a title, sentence case
        $this->assertStringContainsString('<footer class="chapter-end" role="separator" aria-label="End of chapter II · Through">', $body);
        $this->assertStringContainsString('<p>End of chapter II · Through</p>', $body);
        $this->assertStringContainsString('<span class="dots" aria-hidden="true"></span>', $body);
        $this->assertStringContainsString('action="/kudos/add/the-rabbit-hole"', $body);
        $this->assertStringContainsString('href="/story/view/the-rabbit-hole#reviews">Review · 0</a>', $body,
            'the review action lands on the story reviews, counted from the same query');
        // the comp's next-chapter block: "continues below" kicker over the
        // next chapter's own title, the block itself the link
        $this->assertStringContainsString('<div class="next-chapter">', $body);
        $this->assertStringContainsString('<span class="ch-kicker">Chapter III continues below</span>', $body);
        $this->assertStringContainsString('<span class="next-title">Up</span>', $body);
        $this->assertStringContainsString('<a href="/story/read/the-rabbit-hole/3">', $body);
        // the last chapter closes without a next block
        $last = $this->client()->get('/story/read/the-rabbit-hole/3')->body;
        $this->assertStringNotContainsString('class="next-chapter"', $last);
        $this->assertStringContainsString('aria-label="End of chapter III · Up"', $last);
    }

    public function test_focus_variant_renders_dock_and_hides_chrome(): void
    {
        $plain = $this->client()->get('/story/read/the-rabbit-hole/2')->body;
        $this->assertStringNotContainsString('<body class="focus">', $plain);
        $this->assertStringNotContainsString('reader-dock', $plain);
        $focus = $this->client()->get('/story/read/the-rabbit-hole/2', ['focus' => '1'])->body;
        $this->assertStringContainsString('<body class="focus">', $focus, 'the layout hides the site header under body.focus');
        $this->assertStringContainsString('class="reader reader-focus"', $focus);
        $this->assertStringContainsString('<nav class="reader-dock"', $focus);
        $this->assertStringContainsString('href="#contents"', $focus);
        $this->assertStringContainsString('href="#text"', $focus);
        // the exit link is the clean chapter URL
        $this->assertStringContainsString('href="/story/read/the-rabbit-hole/2"', $focus);
        // the focus variant never earns its own canonical URL
        $this->assertStringContainsString('<link rel="canonical" href="https://archive.example/story/read/the-rabbit-hole/2">', $focus);
        $this->assertStringNotContainsString('href="[^"]*focus=1', $focus); // links stay clean; the keys module's data-focus-url attr legitimately carries the query
    }

    public function test_no_javascript_anywhere_on_the_read_page(): void
    {
        $body = $this->client($this->memberId)->get('/story/read/the-rabbit-hole/2')->body;
        // The progressive-enhancement policy (Task 1): exactly one script tag
        // beyond the inert JSON-LD payload, the deferred loader; nothing
        // inline, no event-handler attributes anywhere.
        $this->assertSame(1, preg_match_all('#<script src="/assets/app\.js" defer></script>#', $body),
            'only the deferred loader ships');
        $this->assertSame(2, substr_count($body, '<script'),
            'the loader plus the data-only JSON-LD, nothing else');
        $this->assertSame(0, preg_match_all('/\son[a-z]+\s*=/i', $body),
            'no inline event handler attributes ship');
    }
}
