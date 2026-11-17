<?php // app/Features/Wordfilter/Tests/WordFilterRulesTest.php
namespace App\Features\Wordfilter\Tests;
use PHPUnit\Framework\Attributes\Medium;
use App\Features\Wordfilter\WordFilter;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** What the word filter matches, the seeded starter list, and where it is
 *  (and is not) applied to member submissions. */
#[Medium]
final class WordFilterRulesTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $memberId = 0;
    private int $authorId = 0;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::seededPath('kiption-wfr-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->memberId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        $this->authorId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        WordFilter::forget();
    }

    protected function tearDown(): void
    {
        WordFilter::forget();
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function client(?int $as = null): TestClient
    {
        $c = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-wfr-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-wfr-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
            'signup_min_seconds' => 0, 'registration_mode' => 'open',
        ]));
        return $as === null ? $c : $c->actingAs($as);
    }

    private function list(string ...$words): void
    {
        $this->db->query('DELETE FROM word_filter');
        foreach ($words as $w) $this->db->query('INSERT INTO word_filter (word) VALUES (?)', [$w]);
        WordFilter::forget();
    }

    public function test_matching_is_whole_word_and_ignores_case_and_spacing(): void
    {
        $this->assertTrue(WordFilter::matches('ass', 'ASS'));
        $this->assertTrue(WordFilter::matches('ass', 'an (ass), really'));
        $this->assertFalse(WordFilter::matches('ass', 'passage'));
        $this->assertFalse(WordFilter::matches('ass', 'ass2'), 'a digit glued on is part of a longer word');
        $this->assertFalse(WordFilter::matches('ass', 'éass'), 'a non-ASCII letter glued on is part of a longer word');
        $this->assertTrue(WordFilter::matches('buy now', "BUY \t\n now"));
        $this->assertFalse(WordFilter::matches('buy now', 'now buy'), 'a phrase matches in order only');
    }

    public function test_a_blank_or_regex_looking_word_never_matches_by_accident(): void
    {
        $this->assertFalse(WordFilter::matches('   ', 'anything at all'));
        $this->assertFalse(WordFilter::matches('a.c', 'abc'), 'dots are literal');
        $this->assertTrue(WordFilter::matches('a.c', 'say a.c now'));
        $this->assertTrue(WordFilter::matches('c++', 'I like c++ a lot'));
        $this->assertTrue(WordFilter::matches('100% free', 'it is 100% free today'));
    }

    public function test_find_checks_every_text_and_skips_blank_ones(): void
    {
        $this->list('zorp', 'quux');
        $this->assertNull(WordFilter::find($this->db, '', '   ', 'all clean here'));
        $this->assertSame('quux', WordFilter::find($this->db, 'fine', 'the Quux says hi'));
        $this->assertNull(WordFilter::find($this->db), 'no texts at all');
        $this->list();
        $this->assertNull(WordFilter::find($this->db, 'zorp'), 'an empty list blocks nothing');
        $this->assertNull(WordFilter::refusal($this->db, 'zorp'));
    }

    public function test_refusal_names_the_blocked_word(): void
    {
        $this->list('zorp');
        $this->assertStringContainsString('zorp', (string) WordFilter::refusal($this->db, 'a zorp appears'));
    }

    public function test_parse_lowercases_trims_collapses_spaces_and_dedupes(): void
    {
        $this->assertSame(['banana split', 'zorp', 'état'], WordFilter::parse("  Banana    SPLIT \r\n\r\nzorp\nBANANA split\r\nÉtat\n  \n"));
        $this->assertSame([], WordFilter::parse(" \n\t\n"));
    }

    public function test_the_starter_list_is_installed_and_it_does_not_trip_on_ordinary_prose(): void
    {
        $fresh = new Database('sqlite:' . $this->path);
        $words = WordFilter::words($fresh);
        $this->assertGreaterThan(20, count($words));
        $this->assertContains('adult dating', $words);
        $this->assertNotNull(WordFilter::find($fresh, 'Free ADULT   dating here'));
        $this->assertNull(WordFilter::find($fresh, 'She walked into the garden and read a long chapter about a rabbit.'));
    }

    public function test_the_list_is_remembered_per_request_until_forgotten(): void
    {
        $this->list('zorp');
        $this->assertSame(['zorp'], WordFilter::words($this->db));
        $this->db->query("INSERT INTO word_filter (word) VALUES ('aardvark')");
        $this->assertSame(['zorp'], WordFilter::words($this->db), 'memoised');
        WordFilter::forget();
        $this->assertSame(['aardvark', 'zorp'], WordFilter::words($this->db));
    }

    public function test_saving_an_empty_or_unreadable_list_is_handled(): void
    {
        $admin = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        \App\Adminness::setRole($this->db, $admin, 'admin');
        $res = $this->client($admin)->postWithToken('/wordfilter/save', ['words' => "\xC3\x28"]);
        $this->assertSame(422, $res->status);
        $this->assertNotEmpty(WordFilter::words($this->db), 'the old list survives a refused save');
    }

    public function test_a_blocked_word_stops_a_penname_at_signup_and_creates_no_account(): void
    {
        $this->list('zorp');
        $res = $this->client()->post('/auth/store', ['penname' => 'Zorp Fan', 'email' => 'zf@e.test', 'password' => 'password123', 'confirm' => 'password123']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('zorp', strtolower($res->body));
        $this->assertNull($this->db->one("SELECT 1 AS x FROM users WHERE email = 'zf@e.test'"));
    }

    public function test_a_blocked_word_stops_a_bio_and_keeps_the_old_one(): void
    {
        $this->list('zorp');
        $res = $this->client($this->memberId)->postWithToken('/account/prefs', ['bio' => 'Visit zorp today', 'is_beta' => '', 'default_sort' => 'recent']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('zorp', $res->body);
        $this->assertStringNotContainsString('zorp', (string) $this->db->one('SELECT bio FROM users WHERE id = ?', [$this->memberId])['bio']);
    }

    public function test_a_blocked_word_stops_a_private_message_and_a_profile_contact(): void
    {
        $this->list('zorp');
        $sent = $this->client($this->memberId)->postWithToken('/messages/send/demo-author', ['body' => 'zorp offer']);
        $this->assertSame(422, $sent->status);
        $this->assertStringContainsString('zorp', $sent->body, 'refused for the listed word, not for another reason');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM messages')['c']);
        $contact = $this->client($this->memberId)->postWithToken('/user/contact/demo-author', ['body' => 'zorp offer']);
        $this->assertSame(422, $contact->status);
        $this->assertStringContainsString('zorp', $contact->body);
    }

    public function test_a_blocked_word_stops_a_news_comment(): void
    {
        $this->list('zorp');
        $before = (int) $this->db->one('SELECT COUNT(*) c FROM news_comments')['c'];
        $res = $this->client($this->memberId)->postWithToken('/news/comment/1', ['body' => 'zorp offer']);
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('zorp', $res->body);
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) c FROM news_comments')['c']);
    }

    public function test_a_blocked_word_stops_a_review_reply_and_a_guest_name(): void
    {
        $this->list('zorp');
        $this->assertSame(422, $this->client()->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Lovely', 'rating' => '', 'guest_name' => 'Zorp'])->status, 'guest name');
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM reviews')['c']);
        $this->assertSame(302, $this->client($this->memberId)->postWithToken('/review/add/the-rabbit-hole', ['body' => 'Lovely', 'rating' => '', 'guest_name' => ''])->status);
        $reviewId = (int) $this->db->one('SELECT id FROM reviews')['id'];
        $reply = $this->client($this->authorId)->postWithToken('/review/reply/' . $reviewId, ['body' => 'thanks, zorp']);
        $this->assertSame(422, $reply->status);
        $this->assertStringContainsString('zorp', $reply->body);
    }

    public function test_story_text_is_never_filtered(): void
    {
        $this->list('zorp');
        $res = $this->client($this->authorId)->postWithToken('/story/create', [
            'title' => 'The Zorp Chronicles', 'summary' => 'A zorp appears.',
            'rating_id' => (string) $this->db->one("SELECT id FROM ratings WHERE label = 'Teen'")['id'],
            'categories' => [(string) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id']],
        ]);
        $this->assertSame(302, $res->status, $res->body);
        $slug = (string) $this->db->one("SELECT slug FROM stories WHERE title = 'The Zorp Chronicles'")['slug'];
        $chapter = $this->client($this->authorId)->postWithToken('/chapter/create/' . $slug, ['title' => 'Zorp', 'content' => 'The zorp spoke.', 'notes_before' => '', 'notes_after' => '']);
        $this->assertSame(302, $chapter->status, $chapter->body);
    }
}
