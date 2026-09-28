<?php // tests/ExportTest.php
namespace App\Tests;
use Kip\App;
use Kip\Database;
use Kip\Http\Request;
use Kip\Migrations\Migrator;
use Kip\Testing\TestClient;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase
{
    private string $path = '';
    private string $root = '';
    private Database $db;
    private App $app;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-export-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->root = sys_get_temp_dir() . '/kiption-export-' . uniqid();
        mkdir($this->root . '/upl', 0775, true);
        $this->app = $this->newApp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        foreach (glob($this->root . '/upl/*') ?: [] as $f) @unlink($f);
        foreach (glob($this->root . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->root . '/upl');
        @rmdir($this->root);
    }

    private function newApp(): App
    {
        return new App([
            'app_dir' => dirname(__DIR__) . '/app',
            'env' => 'prod', 'controller_namespace' => 'App\\Controllers\\',
            'views' => dirname(__DIR__) . '/app/views',
            'db' => ['dsn' => 'sqlite:' . $this->path],
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => tempnam(sys_get_temp_dir(), 'kiption-export-mail-') . '.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->root . '/upl'],
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ]);
    }

    private function client(?int $as = null): TestClient
    {
        $this->app = $this->newApp();
        $client = new TestClient($this->app);
        return $as === null ? $client : $client->actingAs($as);
    }

    private function db(): Database
    {
        return $this->db;
    }

    private function memberId(): int
    {
        return (int) $this->db->one("SELECT id FROM users WHERE penname = 'betafriend'")['id'];
    }

    /** Extract one entry's bytes from an epub response via ZipArchive. */
    private function entry(string $epub, string $name): string
    {
        $tmp = $this->root . '/probe.epub';
        file_put_contents($tmp, $epub);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp), 'the archive opens');
        $data = (string) $z->getFromName($name);
        $z->close();
        return $data;
    }

    public function test_html_and_epub_downloads(): void
    {
        $html = $this->client()->get('/story/download/the-rabbit-hole/html');
        $this->assertSame(200, $html->status, $html->body);
        $this->assertStringContainsString('text/html', $html->headers['Content-Type'] ?? '');
        $this->assertSame('attachment; filename="the-rabbit-hole.html"', $html->headers['Content-Disposition'] ?? '');
        $this->assertSame((string) strlen($html->body), $html->headers['Content-Length'] ?? '', 'Content-Length is honest');
        $this->assertSame('nosniff', $html->headers['X-Content-Type-Options'] ?? '', 'the default headers survive the attachment');
        // the standalone document: doctype, charset, metadata block, every chapter
        $this->assertStringContainsString('<!DOCTYPE html>', $html->body);
        $this->assertStringContainsString('<meta charset="utf-8">', $html->body);
        $this->assertStringContainsString('The Rabbit Hole', $html->body);
        $this->assertStringContainsString('Demo Author', $html->body);
        $this->assertStringContainsString('A slow fall into a stranger world.', $html->body);
        foreach (['Down', 'Through', 'Up'] as $title) {
            $this->assertStringContainsString($title, $html->body, "chapter title {$title}");
        }
        $this->assertStringContainsString('<em>down</em>', $html->body, 'prose goes through Markdown::render unpatched');
        $this->assertStringContainsString('Climbing back', $html->body);
        $this->assertStringContainsString('https://archive.example/story/view/the-rabbit-hole', $html->body, 'the source URL rides the metadata block');

        $epub = $this->client()->get('/story/download/the-rabbit-hole/epub');
        $this->assertSame(200, $epub->status);
        $this->assertSame('application/epub+zip', $epub->headers['Content-Type'] ?? '');
        $this->assertSame('attachment; filename="the-rabbit-hole.epub"', $epub->headers['Content-Disposition'] ?? '');
        $this->assertSame((string) strlen($epub->body), $epub->headers['Content-Length'] ?? '');
        $tmp = $this->root . '/x.epub';
        file_put_contents($tmp, $epub->body);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp));
        $this->assertSame("application/epub+zip\n", $z->getFromName('mimetype'), 'first entry, stored, no extra bytes');
        $this->assertNotFalse($z->locateName('META-INF/container.xml'));
        $this->assertNotFalse($z->locateName('OEBPS/content.opf'));
        $this->assertStringContainsString('The Rabbit Hole', (string) $z->getFromName('OEBPS/content.opf'));
        $this->assertStringContainsString('Falling', (string) $z->getFromName('OEBPS/ch1.xhtml'));
        $this->assertNotFalse($z->locateName('OEBPS/nav.xhtml'), 'the EPUB3 nav document exists');
        $this->assertStringContainsString('href="ch1.xhtml"', (string) $z->getFromName('OEBPS/nav.xhtml'), 'the nav links the chapters');
        $this->assertStringNotContainsString('cover-image', (string) $z->getFromName('OEBPS/content.opf'), 'no cover entry when the story has none');
        $z->close();
    }

    public function test_epub_closes_xhtml_void_tags_while_html_stays_html5(): void
    {
        // Finding 3: scene breaks are routine fiction markdown; the EPUB's
        // XHTML closes hr/img after Markdown::render, the HTML export and the
        // site views keep the HTML5 forms untouched.
        $this->db()->query("INSERT INTO chapters (story_id, position, title, content, validated, word_count) VALUES ((SELECT id FROM stories WHERE slug = 'the-rabbit-hole'), 4, 'Scene', ?, 1, 12)",
            ["A line before.\n\n---\n\nA line after with ![pic](https://example.com/pic.png)."]);
        $epub = $this->client()->get('/story/download/the-rabbit-hole/epub');
        $this->assertSame(200, $epub->status);
        $ch4 = $this->entry($epub->body, 'OEBPS/ch4.xhtml');
        $this->assertStringContainsString('A line before.', $ch4);
        $this->assertStringContainsString('<hr/>', $ch4, 'scene breaks close for XHTML');
        $this->assertStringNotContainsString('<hr>', $ch4);
        $this->assertStringContainsString('<img src="https://example.com/pic.png" alt="pic"/>', $ch4, 'markdown images close for XHTML');
        $this->assertStringContainsString('href="style.css"', $ch4, 'the embedded stylesheet links in');

        $html = $this->client()->get('/story/download/the-rabbit-hole/html');
        $this->assertSame(200, $html->status);
        $this->assertStringContainsString('<hr>', $html->body, 'the HTML export keeps HTML5 void tags');
        $this->assertStringNotContainsString('<hr/>', $html->body);
        $this->assertStringContainsString('<img src="https://example.com/pic.png" alt="pic">', $html->body);
    }

    public function test_opf_metadata_carries_title_creator_language_and_identifier(): void
    {
        $epub = $this->client()->get('/story/download/the-rabbit-hole/epub');
        $this->assertSame(200, $epub->status);
        $opf = $this->entry($epub->body, 'OEBPS/content.opf');
        $this->assertStringContainsString('<dc:title>The Rabbit Hole</dc:title>', $opf);
        $this->assertStringContainsString('<dc:creator>Demo Author</dc:creator>', $opf);
        $this->assertStringContainsString('<dc:language>en</dc:language>', $opf, "the story's language column, defaulting to en");
        $this->assertStringContainsString('<dc:identifier id="pub-id">https://archive.example/story/view/the-rabbit-hole</dc:identifier>', $opf, 'the identifier is the story URL');
        $this->assertStringContainsString('unique-identifier="pub-id"', $opf);
        $this->assertStringContainsString('properties="nav"', $opf, 'nav.xhtml is the EPUB3 nav');
        $this->assertStringContainsString('<spine>', $opf);
        $this->assertStringContainsString('<itemref idref="ch1"/>', $opf);
        $this->assertStringContainsString('<itemref idref="ch3"/>', $opf);
        // a story with an explicit language column carries it through
        $this->db()->query("UPDATE stories SET language = 'pt' WHERE slug = 'the-rabbit-hole'");
        $opfPt = $this->entry($this->client()->get('/story/download/the-rabbit-hole/epub')->body, 'OEBPS/content.opf');
        $this->assertStringContainsString('<dc:language>pt</dc:language>', $opfPt);
    }

    public function test_cover_image_embedded_when_present_and_skipped_when_missing(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        file_put_contents($this->root . '/upl/9f2ab3c1d4e5f6a7.png', $png);
        $this->db()->query("UPDATE stories SET cover_path = '/uploads/9f2ab3c1d4e5f6a7.png' WHERE slug = 'the-rabbit-hole'");
        $epub = $this->client()->get('/story/download/the-rabbit-hole/epub');
        $this->assertSame(200, $epub->status, 'a present cover never breaks the build');
        $this->assertSame($png, $this->entry($epub->body, 'OEBPS/cover.png'), 'the cover bytes round-trip from the uploads dir');
        $opf = $this->entry($epub->body, 'OEBPS/content.opf');
        $this->assertStringContainsString('href="cover.png"', $opf);
        $this->assertStringContainsString('media-type="image/png"', $opf);
        $this->assertStringContainsString('properties="cover-image"', $opf);

        // graceful skip: the column points at a file that is not there
        $this->db()->query("UPDATE stories SET cover_path = '/uploads/ghost.png' WHERE slug = 'the-rabbit-hole'");
        $skip = $this->client()->get('/story/download/the-rabbit-hole/epub');
        $this->assertSame(200, $skip->status, 'a missing cover file skips, never fatal');
        $tmp = $this->root . '/probe.epub';
        file_put_contents($tmp, $skip->body);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp));
        $this->assertFalse($z->locateName('OEBPS/cover.png'), 'no cover entry for an absent file');
        $this->assertStringNotContainsString('cover-image', (string) $z->getFromName('OEBPS/content.opf'));
        $z->close();
    }

    public function test_download_gates_match_chapter_reads(): void
    {
        // adult without the cookie renders the gate page, not a download
        $gate = $this->app->handle(new Request('GET', '/story/download/after-hours/epub', [], [], []));
        $this->assertSame(200, $gate->status);
        $this->assertStringContainsString('Contains explicit adult content.', $gate->body);
        $this->assertArrayNotHasKey('Content-Disposition', $gate->headers, 'the gate page is not an attachment');
        $this->assertStringNotContainsString('Body.', $gate->body, 'the prose stays behind the gate');
        $this->assertStringContainsString('return_to=/story/download/after-hours/epub', $gate->body, 'the gate returns to the download');
        // with the cookie the download flows
        $read = $this->app->handle(new Request('GET', '/story/download/after-hours/epub', [], [], ['age_ok' => '1']));
        $this->assertSame('application/epub+zip', $read->headers['Content-Type'] ?? '');
        $this->assertSame('attachment; filename="after-hours.epub"', $read->headers['Content-Disposition'] ?? '');
        // restricted 404s for guests, renders for members (the download IS reading)
        $this->db()->query("UPDATE stories SET is_restricted = 1 WHERE slug = 'the-rabbit-hole'");
        $this->assertSame(404, $this->client()->get('/story/download/the-rabbit-hole/html')->status);
        $this->assertSame(200, $this->client($this->memberId())->get('/story/download/the-rabbit-hole/html')->status);
        // unknown slug and unknown format 404
        $this->assertSame(404, $this->client()->get('/story/download/nope/html')->status);
        $this->assertSame(404, $this->client()->get('/story/download/the-rabbit-hole/pdf')->status);
    }

    public function test_zipwriter_validates_entry_names_and_round_trips(): void
    {
        $w = new \App\Export\ZipWriter();
        foreach (['../evil.txt', "a b.txt", "line\nbreak", "semi;colon"] as $bad) {
            try {
                $w->add($bad, 'x');
                $this->fail("illegal entry name accepted: {$bad}");
            } catch (\InvalidArgumentException) {
                // the name whitelist holds
            }
        }
        $w2 = new \App\Export\ZipWriter();
        $binary = pack('V', 0x04034b50) . "\x00\x01\xff" . str_repeat("\xde\xad\xbe\xef", 64);
        $w2->add('a.txt', 'first');
        $w2->add('dir/b.bin', $binary);
        $w2->add('empty.txt', '');
        $tmp = $this->root . '/plain.zip';
        file_put_contents($tmp, $w2->finish());
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp), 'a two-entry stored archive opens');
        $this->assertSame(3, $z->numFiles);
        $this->assertSame('first', $z->getFromName('a.txt'));
        $this->assertSame($binary, $z->getFromName('dir/b.bin'), 'binary payload with high bytes round-trips (CRC pinned)');
        $this->assertSame('', $z->getFromName('empty.txt'));
        $this->assertSame(0, $z->statIndex(0)['comp_method'] ?? -1, 'entries are stored, not deflated');
        $z->close();
        // an empty archive is still a valid EOCD-only zip
        $tmp2 = $this->root . '/empty.zip';
        file_put_contents($tmp2, (new \App\Export\ZipWriter())->finish());
        $z2 = new \ZipArchive();
        $this->assertTrue($z2->open($tmp2));
        $this->assertSame(0, $z2->numFiles);
        $z2->close();
    }
}
