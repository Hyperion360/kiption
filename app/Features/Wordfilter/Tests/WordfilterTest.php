<?php // app/Features/Wordfilter/Tests/WordfilterTest.php
namespace App\Features\Wordfilter\Tests;
use Kip\App;
use Kip\Database;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

/** The admin word-filter screen: its limits refuse with the right message. */
final class WordfilterTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-wf-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->adminId = (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
        \App\Adminness::setRole($this->db, $this->adminId, 'admin');
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
    }

    private function save(string $words): \Kip\Http\Response
    {
        return $this->admin()->postWithToken('/wordfilter/save', ['words' => $words]);
    }

    private function admin(): TestClient
    {
        $c = new TestClient(new App([
            'app_dir' => dirname(__DIR__, 4) . '/app',
            'env' => 'prod',
            'views' => dirname(__DIR__, 4) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => sys_get_temp_dir() . '/kiption-wf-mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => sys_get_temp_dir() . '/kiption-wf-upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]));
        return $c->actingAs($this->adminId);
    }

    public function test_too_many_entries_is_refused(): void
    {
        $res = $this->save(implode("\n", array_map(fn($i) => "w$i", range(1, 1001))));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('limited to 1000 entries', $res->body);
    }

    public function test_an_over_long_word_gets_the_too_long_message(): void
    {
        $res = $this->save(str_repeat('a', 101));
        $this->assertSame(422, $res->status);
        $this->assertStringContainsString('at most 100 characters', $res->body);
    }

    public function test_markup_characters_are_refused(): void
    {
        foreach (['<script>', 'a>b', 'say "hi"'] as $w) {
            $res = $this->save($w);
            $this->assertSame(422, $res->status, $w);
            $this->assertStringNotContainsString('<script>', $res->body);
        }
    }

    public function test_a_normal_list_saves(): void
    {
        $this->assertSame('/wordfilter?saved=1', $this->save("badword\nanother one")->headers['Location'] ?? '');
    }


    public function test_text_the_regex_cannot_read_is_refused_not_waved_through(): void
    {
        $this->assertTrue(\App\Features\Wordfilter\WordFilter::matches('spam', "hello \xC3\x28 world"), 'malformed UTF-8 makes preg_match fail; that must block');
        $this->assertFalse(\App\Features\Wordfilter\WordFilter::matches('spam', 'hello world'));
    }

    public function test_try_a_sentence_answers_with_the_real_filter_and_keeps_nothing(): void
    {
        $this->save("darn\nrude phrase");
        $c = $this->admin();
        $logged = (int) $this->db->one('SELECT COUNT(*) AS n FROM activity_log')['n'];
        $hit = $c->postWithToken('/wordfilter/try', ['sentence' => 'What a RUDE   phrase <b>that</b> is'])->body;
        $this->assertStringContainsString(\App\Lang::t('wordfilter.try_blocked', ['word' => 'rude phrase']), $hit);
        $this->assertStringContainsString('value="What a RUDE   phrase &lt;b&gt;that&lt;/b&gt; is"', $hit);
        $clean = $c->postWithToken('/wordfilter/try', ['sentence' => 'Darning socks is fine'])->body;
        $this->assertStringContainsString(\App\Lang::t('wordfilter.try_clean'), $clean);
        $this->assertSame($logged, (int) $this->db->one('SELECT COUNT(*) AS n FROM activity_log')['n']);
        $this->assertStringNotContainsString('role="status"', $c->postWithToken('/wordfilter/try', ['sentence' => '  '])->body);
    }
}
