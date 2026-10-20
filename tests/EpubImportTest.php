<?php // tests/EpubImportTest.php
namespace App\Tests;
use App\Import\Epub;
use Kip\Database;
use PHPUnit\Framework\TestCase;

/** Harness for the EPUB bulk importer: a fully migrated and seeded temp
 *  database plus throwaway uploads and fixtures directories, removed
 *  recursively on teardown. The later pipeline tasks build their tests on
 *  this scaffold. */
final class EpubImportTest extends TestCase
{
    private string $path = '';
    private string $uploads = '';
    private string $fixtures = '';
    private Database $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'kiption-epub-db-') . '.sqlite';
        $this->db = new Database('sqlite:' . $this->path);
        (new \Kip\Migrations\Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($this->db);
        $this->uploads = $this->tempDir('kiption-epub-upl-');
        $this->fixtures = $this->tempDir('kiption-epub-fx-');
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->uploads);
        $this->rmDir($this->fixtures);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -strlen('.sqlite'))); // the zero-byte tempnam stub itself
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
        mkdir($dir, 0775, true);
        return $dir;
    }

    /** Pure-PHP recursive removal, the DoctorTest idiom. */
    private function rmDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) return;
        $ri = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $f) { $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f); }
        @rmdir($dir);
    }

    public function test_epub_imports_ledger_exists_with_exactly_the_ledger_columns(): void
    {
        $cols = array_map(static fn(array $c): string => $c['name'], $this->db->all('PRAGMA table_info(epub_imports)'));
        $this->assertSame(['sha256', 'story_id', 'imported_at'], $cols, 'the epub_imports ledger carries exactly the three ledger columns');
    }

    // ------------------------------------------------------------------
    // Task 2: bounded reading. The fixtures build real zips on the fly and
    // always hand back the ABSOLUTE path; every inspect() call passes that
    // return value, never a bare filename.
    // ------------------------------------------------------------------

    /** Builds a real zip at fixtures/<name> via ZipArchive and returns its
     *  absolute path. $entries maps archive entry names to string contents;
     *  $opts['files'] (entry name => disk path) adds entries from files on
     *  disk, which is how the sparse-file bomb fixtures reach the zip.
     *  $opts['book'] merges a full generated book (bookEntries) in front of
     *  the hand-written entries, which is how the structure tests reach a
     *  real container and OPF without spelling out the XML every time. */
    private function makeEpub(string $name, array $entries, array $opts = []): string
    {
        if (isset($opts['book'])) {
            $entries = $this->bookEntries($opts['book']) + $entries;
        }
        $path = $this->fixtures . '/' . $name;
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail("fixture zip {$name} failed to open for writing");
        }
        foreach ($entries as $entryName => $content) {
            $zip->addFromString((string) $entryName, (string) $content);
        }
        foreach ($opts['files'] ?? [] as $entryName => $diskPath) {
            $zip->addFile((string) $diskPath, (string) $entryName);
        }
        if (!$zip->close()) {
            $this->fail("fixture zip {$name} failed to close");
        }
        return $path;
    }

    /** A sparse file of exactly $bytes: ftruncate past the end leaves a hole
     *  that allocates no blocks, so a 16 MiB declared bomb costs kilobytes of
     *  real disk and compresses to almost nothing inside the fixture. */
    private function bigFileOnDisk(int $bytes): string
    {
        $path = tempnam($this->fixtures, 'big-');
        $f = fopen($path, 'rb+');
        if ($f === false || ftruncate($f, $bytes) === false) {
            $this->fail("sparse fixture failed to build at {$bytes} bytes");
        }
        fclose($f);
        return $path;
    }

    /** A full book fixture: META-INF/container.xml pointing at a
     *  configurable OPF ($b['opf'], default OEBPS/content.opf), the OPF
     *  itself (dc:title, dc:creator, dc:description with inline <b> and an
     *  entity, dc:language), a manifest whose chapter hrefs live in an
     *  OEBPS/Text/ subdirectory, a cover png under one of the two
     *  conventions, and a spine of N xhtml idrefs. Per-chapter href/entry
     *  pairs override the defaults ($b['hrefs'], each pair [href, entry]).
     *  Toggles: cover 'property'|'meta'|'none', rootfile false (container
     *  without a rootfile), doctype, truncate, escape (an href resolving
     *  above root), missing (a spine href with no zip entry), emptySpine,
     *  and chapters N (513 reproduces the spine cap). */
    private function bookEntries(array $b = []): array
    {
        $opfPath = $b['opf'] ?? 'OEBPS/content.opf';
        $dir = dirname($opfPath);
        if ($dir === '.' || $dir === '/') { $dir = ''; }
        $prefix = $dir === '' ? '' : $dir . '/';
        $pairs = $b['hrefs'] ?? [];
        $count = $b['chapters'] ?? 3;
        while (count($pairs) < $count) {
            $n = count($pairs) + 1;
            $pairs[] = ['Text/ch' . $n . '.xhtml', 'Text/ch' . $n . '.xhtml'];
        }
        $entries = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => '<?xml version="1.0"?>' . "\n"
                . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
                . (($b['rootfile'] ?? true) === false ? '' : sprintf(
                    '<rootfile full-path="%s" media-type="application/oebps-package+xml"/>', $opfPath))
                . '</rootfiles></container>',
        ];
        $items = [];
        $refs = [];
        foreach ($pairs as $n => [$href, $entry]) {
            $id = 'ch' . ($n + 1);
            $items[] = sprintf('<item id="%s" href="%s" media-type="application/xhtml+xml"/>', $id, $href);
            if (empty($b['emptySpine'])) { $refs[] = sprintf('<itemref idref="%s"/>', $id); }
            $entries[$prefix . $entry] = sprintf(
                '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>%s</title></head>'
                . '<body><h2>%s</h2><p>Prose for chapter %d.</p></body></html>',
                $id, $id, $n + 1);
        }
        $cover = $b['cover'] ?? 'property';
        if ($cover === 'property') {
            $items[] = '<item id="cover-img" href="cover.png" media-type="image/png" properties="cover-image"/>';
        } elseif ($cover === 'meta') {
            $items[] = '<item id="cover-page" href="cover.png" media-type="image/png"/>';
        }
        if ($cover !== 'none') {
            $entries[$prefix . 'cover.png'] = "\x89PNG\r\n\x1a\n fixture bytes, never inspected";
        }
        if (!empty($b['escape'])) {
            $items[] = '<item id="bad-esc" href="../../x.xhtml" media-type="application/xhtml+xml"/>';
            $refs[] = '<itemref idref="bad-esc"/>';
        }
        if (!empty($b['missing'])) {
            $items[] = '<item id="bad-miss" href="Text/ghost.xhtml" media-type="application/xhtml+xml"/>';
            $refs[] = '<itemref idref="bad-miss"/>';
        }
        $opf = '<?xml version="1.0"?>' . "\n"
            . (empty($b['doctype']) ? '' : "<!DOCTYPE package>\n")
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>' . ($b['title'] ?? 'Stamped Title') . '</dc:title>'
            . '<dc:creator>' . ($b['creator'] ?? 'author-one') . '</dc:creator>'
            . '<dc:description>' . ($b['description'] ?? 'A summary with <b>bold</b> &amp; inline markup.') . '</dc:description>'
            . '<dc:language>' . ($b['language'] ?? 'EN') . '</dc:language>'
            . ($cover === 'meta' ? '<meta name="cover" content="cover-page"/>' : '')
            . '</metadata>'
            . '<manifest>' . implode('', $items) . '</manifest>'
            . '<spine>' . implode('', $refs) . '</spine>'
            . '</package>';
        if (!empty($b['truncate'])) { $opf = substr($opf, 0, intdiv(strlen($opf), 2)); }
        $entries[$opfPath] = $opf;
        return $entries;
    }

    public function test_a_legit_zip_passes_the_bounds_and_stops_at_the_missing_container(): void
    {
        $epub = $this->makeEpub('clean.epub', [
            'mimetype' => 'application/epub+zip',
            'OEBPS/content.opf' => '<?xml version="1.0"?><package/>',
        ]);
        // no META-INF/container.xml: the bounds pass, the container gate
        // refuses; the structure pass itself is Task 3's work
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an EPUB/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_an_entry_declaring_over_16_mib_is_refused_before_any_payload_read(): void
    {
        $big = $this->bigFileOnDisk(16 * 1024 * 1024 + 1);
        $epub = $this->makeEpub('declared-file-bomb.epub', ['mimetype' => 'application/epub+zip'],
            ['files' => ['OEBPS/big.bin' => $big]]);
        $before = scandir($this->fixtures);
        try {
            (new Epub($this->db, $this->uploads))->inspect($epub);
            $this->fail('an entry declaring over 16 MiB must be refused');
        } catch (\RuntimeException $ex) {
            $this->assertMatchesRegularExpression('/16 MiB/', $ex->getMessage());
        }
        // the probe: the refusal came from the central directory alone, no
        // payload read ever ran, so the fixtures dir holds exactly what it
        // held before (no extracted files next to the fixture)
        $this->assertSame($before, scandir($this->fixtures), 'no payload read: the fixtures dir gained no extracted files');
    }

    public function test_a_zip_with_5000_entries_is_refused_at_the_4096_member_cap(): void
    {
        $entries = [];
        for ($i = 0; $i < 5000; $i++) { $entries['x' . $i . '.txt'] = 'x'; }
        $epub = $this->makeEpub('member-bomb.epub', $entries);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/4096/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_declared_sizes_summing_over_64_mib_are_refused(): void
    {
        // five entries at 15 MiB each: every entry sits under the per-entry
        // cap while the declared sum reaches 75 MiB, over the archive cap
        $big = $this->bigFileOnDisk(15 * 1024 * 1024);
        $epub = $this->makeEpub('declared-total-bomb.epub', ['mimetype' => 'application/epub+zip'],
            ['files' => [
                'OEBPS/a.bin' => $big, 'OEBPS/b.bin' => $big, 'OEBPS/c.bin' => $big,
                'OEBPS/d.bin' => $big, 'OEBPS/e.bin' => $big,
            ]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/64 MiB/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    // ------------------------------------------------------------------
    // Task 3: container, OPF, spine, cover resolution. The generated book
    // fixtures (bookEntries via $opts['book']) carry a real container and
    // OPF; every assertion below runs against inspect()'s structure array.
    // ------------------------------------------------------------------

    public function test_a_real_book_exposes_metadata_and_resolved_spine_paths(): void
    {
        $epub = $this->makeEpub('book.epub', [], ['book' => ['chapters' => 3]]);
        $s = (new Epub($this->db, $this->uploads))->inspect($epub);
        $this->assertSame('Stamped Title', $s['title']);
        $this->assertSame('author-one', $s['creator']);
        // the summary is PLAIN text: the inline <b> is gone, the &amp;
        // entity is decoded, no tag fragments survive
        $this->assertSame('A summary with bold & inline markup.', $s['summary']);
        $this->assertSame('en', $s['language']);
        // href-as-given would be 'Text/chN.xhtml'; resolution prefixes the
        // OPF's own directory, so this assertion fails against the stub
        $this->assertSame(
            ['OEBPS/Text/ch1.xhtml', 'OEBPS/Text/ch2.xhtml', 'OEBPS/Text/ch3.xhtml'],
            $s['spine']
        );
        // the EPUB 3 properties="cover-image" convention resolves to the
        // archive entry name
        $this->assertSame('OEBPS/cover.png', $s['cover']);
    }

    public function test_a_summary_over_1000_characters_is_capped(): void
    {
        $epub = $this->makeEpub('long-summary.epub', [], ['book' => ['description' => str_repeat('x', 1200)]]);
        $s = (new Epub($this->db, $this->uploads))->inspect($epub);
        $this->assertSame(1000, strlen($s['summary']));
        $this->assertSame(str_repeat('x', 1000), $s['summary']);
    }

    public function test_manifest_hrefs_are_percent_decoded_and_fragment_stripped(): void
    {
        $epub = $this->makeEpub('escaped-hrefs.epub', [], ['book' => [
            'chapters' => 2,
            'hrefs' => [
                ['Text/ch%20one.xhtml#top', 'Text/ch one.xhtml'],
                ['./Text/ch2.xhtml', 'Text/ch2.xhtml'],
            ],
        ]]);
        $s = (new Epub($this->db, $this->uploads))->inspect($epub);
        // percent escapes decode, fragments drop, a leading ./ goes away,
        // and each still lands on the OPF-relative prefix
        $this->assertSame(['OEBPS/Text/ch one.xhtml', 'OEBPS/Text/ch2.xhtml'], $s['spine']);
    }

    public function test_the_meta_name_cover_convention_resolves_too(): void
    {
        $epub = $this->makeEpub('meta-cover.epub', [], ['book' => ['cover' => 'meta']]);
        $s = (new Epub($this->db, $this->uploads))->inspect($epub);
        $this->assertSame('OEBPS/cover.png', $s['cover']);
    }

    public function test_a_book_with_no_cover_convention_reports_a_null_cover(): void
    {
        $epub = $this->makeEpub('no-cover.epub', [], ['book' => ['cover' => 'none']]);
        $s = (new Epub($this->db, $this->uploads))->inspect($epub);
        $this->assertNull($s['cover']);
    }

    public function test_a_container_without_a_rootfile_is_no_package_document(): void
    {
        $epub = $this->makeEpub('no-rootfile.epub', [], ['book' => ['rootfile' => false]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no package document/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_a_doctype_in_the_opf_is_refused(): void
    {
        $epub = $this->makeEpub('doctype.epub', [], ['book' => ['doctype' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/refuses DOCTYPE/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_truncated_opf_xml_is_an_unreadable_package_document(): void
    {
        $epub = $this->makeEpub('truncated.epub', [], ['book' => ['truncate' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unreadable package document/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_an_empty_spine_has_no_readable_chapters(): void
    {
        $epub = $this->makeEpub('empty-spine.epub', [], ['book' => ['emptySpine' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no readable chapters/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_a_spine_href_resolving_above_root_escapes_the_archive(): void
    {
        $epub = $this->makeEpub('escape.epub', [], ['book' => ['escape' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes the archive/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_a_spine_href_with_no_zip_entry_is_missing_from_the_archive(): void
    {
        $epub = $this->makeEpub('missing-href.epub', [], ['book' => ['missing' => true]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing from the archive/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }

    public function test_a_spine_over_512_chapters_is_refused(): void
    {
        $epub = $this->makeEpub('over-cap.epub', [], ['book' => ['chapters' => 513]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/spine exceeds 512 chapters/');
        (new Epub($this->db, $this->uploads))->inspect($epub);
    }
}
