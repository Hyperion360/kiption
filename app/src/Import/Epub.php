<?php // app/src/Import/Epub.php
namespace App\Import;
use Kip\Database;

/** The EPUB reader: hostile ZIPs by default (the pentest's tar-bomb finding
 *  is the precedent), so bounds are enforced in two layers. Declared sizes
 *  come from the central directory and every entry passes them BEFORE any
 *  payload is read: member count, per-entry bytes, archive total. Then
 *  actual bytes accumulate across every read and re-enforce the archive
 *  total, so a lying central directory cannot spend reads it never
 *  declared. getFromIndex() returning false is an error, never an empty
 *  string. inspect() is pure reading (no DB); importFile() is the
 *  persistence half: one transaction per file, the epub_imports ledger row
 *  written only on full commit, and a stored cover unlinked on rollback. */
final class Epub
{
    public const MAX_MEMBERS = 4096;
    public const MAX_FILE_BYTES = 16 * 1024 * 1024;  // 16 MiB per entry
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024; // 64 MiB per archive
    public const MAX_CHAPTERS = 512;                 // spine documents per book

    private ?\ZipArchive $zip = null;
    /** @var array<int, array<string, mixed>> the central directory stat list
     *  from the last inspect(), kept so structure passes read entries by
     *  index without re-walking the archive */
    private array $stats = [];
    /** @var array<string, int> entry name => central directory index, built
     *  once per inspect() so lookups never re-scan the stat list */
    private array $indices = [];
    /** actual bytes read since the last inspect(), the accumulated half of
     *  the archive cap */
    private int $actualTotal = 0;
    /** the last importFile() report row, or null before the first call */
    private ?array $lastReport = null;
    /** lowercased penname => user id, built on the first creator-based
     *  resolution and reused after: a directory import reads users once,
     *  not once per file */
    private ?array $pennameIndex = null;

    public function __construct(
        private Database $db,
        private string $uploadsDir,   // cover storage (import half)
        private bool $coverStrict = false, // test seam: cover skips become throws
    ) {}

    /** The last importFile() report row: file, status (imported, would
     *  import, skipped, failed), title, chapters, words, storyId, notes.
     *  The CLI prints one row per file. */
    public function lastReport(): ?array
    {
        return $this->lastReport;
    }

    /** Bounds pass: open the archive, walk the central directory once
     *  enforcing the member count, per-entry declared bytes, and the
     *  declared total, then run the structure pass. No entry payload is
     *  read here; a zip that fails any cap is refused without a single
     *  getFromIndex call. */
    public function inspect(string $path): array
    {
        // a directory import inspects many files through one instance
        if ($this->zip !== null) { $this->zip->close(); $this->zip = null; }
        $zip = new \ZipArchive();
        if (($code = $zip->open($path, \ZipArchive::RDONLY)) !== true) {
            throw new \RuntimeException("not a readable zip archive (code {$code})");
        }
        $this->zip = $zip;
        if ($zip->numFiles > self::MAX_MEMBERS) {
            throw new \RuntimeException(sprintf('zip holds %d entries, over the %d member cap', $zip->numFiles, self::MAX_MEMBERS));
        }
        $this->stats = [];
        $declaredTotal = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException("zip central directory unreadable at index {$i}");
            }
            $this->stats[] = $stat;
            $size = (int) $stat['size'];
            if ($size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf("zip entry '%s' declares %d bytes, over the %d MiB per-entry cap", $stat['name'], $size, self::mib(self::MAX_FILE_BYTES)));
            }
            $declaredTotal += $size;
            if ($declaredTotal > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException(sprintf('zip entries declare %d bytes total, over the %d MiB archive cap', $declaredTotal, self::mib(self::MAX_TOTAL_BYTES)));
            }
        }
        $this->actualTotal = 0;
        $this->indices = [];
        foreach ($this->stats as $i => $stat) {
            $this->indices[$stat['name']] ??= $i; // first entry wins on duplicates
        }
        return $this->readStructure();
    }

    /** One length-capped payload read. MAX_FILE_BYTES + 1 is asked from the
     *  zip so an oversized entry is DETECTED (strlen past the cap) rather
     *  than silently truncated; a false return is a hard error, never an
     *  empty string. Every successful read accumulates actual bytes against
     *  the archive cap, the actual half of the bounds contract. */
    public function readEntry(int $i): string
    {
        if ($this->zip === null) {
            throw new \RuntimeException('readEntry runs only after inspect() opened the archive');
        }
        $data = $this->zip->getFromIndex($i, self::MAX_FILE_BYTES + 1);
        if ($data === false) {
            throw new \RuntimeException(sprintf("zip entry '%s' (index %d) failed to read", $this->stats[$i]['name'] ?? $i, $i));
        }
        if (strlen($data) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(sprintf('zip entry %d read %d actual bytes, over the %d MiB per-entry cap', $i, strlen($data), self::mib(self::MAX_FILE_BYTES)));
        }
        $this->actualTotal += strlen($data);
        if ($this->actualTotal > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException(sprintf('zip reads total %d actual bytes, over the %d MiB archive cap', $this->actualTotal, self::mib(self::MAX_TOTAL_BYTES)));
        }
        return $data;
    }

    /** A byte cap in whole MiB, the human-readable form the refusal
     *  messages quote: interpolated from the constants so the words and
     *  the enforcement cannot drift apart. */
    private static function mib(int $bytes): int
    {
        return intdiv($bytes, 1024 * 1024);
    }

    /** The structure pass: container, OPF, metadata, manifest, spine, cover.
     *  Every parsed document is DOCTYPE-free by force (LIBXML_NONET alone
     *  does not stop internal entity expansion, and no EPUB package
     *  document ever legitimately carries a DOCTYPE); every href resolves
     *  against the OPF's own directory per decision 2. Only container.xml
     *  and the OPF are read; chapter payloads stay for the import pass. */
    private function readStructure(): array
    {
        $containerIdx = $this->entryIndex('META-INF/container.xml');
        if ($containerIdx === null) {
            throw new \RuntimeException('not an EPUB: META-INF/container.xml is missing');
        }
        $container = $this->readEntry($containerIdx);
        $this->refuseDoctype('META-INF/container.xml', $container);
        if (!preg_match('~<rootfile[^>]*full-path="([^"]+)"~', $container, $m)) {
            throw new \RuntimeException('no package document: META-INF/container.xml names no rootfile full-path');
        }
        $opfPath = (string) preg_replace('~^\./~', '', $m[1]);
        $opfIdx = $this->entryIndex($opfPath);
        if ($opfIdx === null) {
            throw new \RuntimeException(sprintf("the rootfile full-path is missing from the archive: no entry provides '%s'", $opfPath));
        }
        $opf = $this->readEntry($opfIdx);
        $this->refuseDoctype($opfPath, $opf);
        $doc = new \DOMDocument();
        if (!$doc->loadXML($opf, LIBXML_NONET | LIBXML_NOERROR)) {
            throw new \RuntimeException(sprintf("unreadable package document: '%s' does not parse as XML", $opfPath));
        }
        $opfDir = ($d = dirname($opfPath)) === '.' ? '' : $d;
        $meta = $this->readMetadata($doc);
        // an empty or whitespace dc:title would import a story titled '':
        // refuse here at the structure pass, where the other hard refusals
        // live, so a dry run reports the same refusal a commit would
        if (trim($meta['title']) === '') {
            throw new \RuntimeException('the EPUB carries no usable title');
        }
        $manifest = $this->readManifest($doc, $opfDir);
        $spineNotes = [];
        return [
            'stats' => $this->stats,
            'opfPath' => $opfPath,
            'title' => $meta['title'],
            'creator' => $meta['creator'],
            'summary' => $meta['summary'],
            'language' => $meta['language'],
            'cover' => $this->findCover($doc, $manifest),
            'spine' => $this->readSpine($doc, $manifest, $spineNotes),
            'spineNotes' => $spineNotes,
            'manifest' => $manifest,
        ];
    }

    /** Central directory index for an archive path, or null when no entry
     *  provides it. */
    private function entryIndex(string $name): ?int
    {
        return $this->indices[$name] ?? null;
    }

    /** A DOCTYPE is refused before any parse: LIBXML_NONET alone does not
     *  stop internal entity expansion, and an EPUB package document never
     *  legitimately carries one. */
    private function refuseDoctype(string $what, string $xml): void
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException(sprintf('refuses DOCTYPE: %s declares a DOCTYPE, which no EPUB package document ever needs', $what));
        }
    }

    /** Decision 2, binding: strip the #fragment, rawurldecode, strip a
     *  leading ./, then prefix the OPF's own directory; dot segments
     *  normalize as they go, and a path that is absolute or walks above the
     *  archive root refuses as an escape. */
    private function resolveHref(string $opfDir, string $href): string
    {
        $href = rawurldecode((string) preg_replace('~#.*~', '', $href));
        $href = (string) preg_replace('~^\./~', '', $href);
        if ($href !== '' && $href[0] === '/') {
            throw new \RuntimeException(sprintf("a manifest href escapes the archive: '%s' is absolute, and no archive path is", $href));
        }
        $segments = [];
        foreach (explode('/', ($opfDir === '' ? '' : $opfDir . '/') . $href) as $seg) {
            if ($seg === '' || $seg === '.') { continue; }
            if ($seg === '..') {
                if ($segments === []) {
                    throw new \RuntimeException(sprintf("a manifest href escapes the archive: '%s' walks above the archive root", $href));
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return implode('/', $segments);
    }

    /** First dc:title, first dc:creator, dc:description stripped to plain
     *  text (tags off, entities decoded, whitespace collapsed, 1000 chars),
     *  dc:language lowercased. An absent field is an empty string; refusal
     *  is not metadata's job (the one exception, the empty title, refuses
     *  in readStructure). The collapse carries a non-u fallback: a /u
     *  pattern returns null on invalid UTF-8, and the naive cast would
     *  silently hand the import an empty summary. */
    private function readMetadata(\DOMDocument $doc): array
    {
        $summary = html_entity_decode(strip_tags($this->firstDc($doc, 'description')?->textContent ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('~\s+~u', ' ', $summary);
        if ($collapsed === null) { $collapsed = (string) preg_replace('~\s+~', ' ', $summary); }
        $summary = trim($collapsed);
        if (mb_strlen($summary) > 1000) { $summary = mb_substr($summary, 0, 1000); }
        return [
            'title' => $this->firstDc($doc, 'title')?->textContent ?? '',
            'creator' => $this->firstDc($doc, 'creator')?->textContent ?? '',
            'summary' => $summary,
            'language' => mb_strtolower($this->firstDc($doc, 'language')?->textContent ?? ''),
        ];
    }

    /** The first Dublin Core element of $local, namespace-aware with a
     *  literal-prefix fallback, so a package that binds dc under another
     *  prefix still reads. */
    private function firstDc(\DOMDocument $doc, string $local): ?\DOMElement
    {
        $node = $doc->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', $local)->item(0)
            ?? $doc->getElementsByTagName('dc:' . $local)->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    /** Manifest items keyed by id: resolved archive path (decision 2),
     *  media-type, and the space-separated properties list. An escape is
     *  refused the moment any href resolves, wherever it sits. */
    private function readManifest(\DOMDocument $doc, string $opfDir): array
    {
        $manifest = [];
        foreach ($doc->getElementsByTagName('item') as $item) {
            $id = $item->getAttribute('id');
            $href = $item->getAttribute('href');
            if ($id === '' || $href === '') { continue; }
            $manifest[$id] = [
                'path' => $this->resolveHref($opfDir, $href),
                'mediaType' => $item->getAttribute('media-type'),
                'properties' => preg_split('~\s+~', trim($item->getAttribute('properties')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ];
        }
        return $manifest;
    }

    /** Spine idrefs in document order, filtered to xhtml media-types and
     *  capped at MAX_CHAPTERS (decision 3: an over-cap spine refuses before
     *  any chapter payload is read). itemrefs carrying linear="no" are
     *  auxiliary content and skip with a counted note, a second itemref to
     *  an already-resolved path skips with a counted note, and the
     *  FanFicFare front matter skips on the manifest idref fingerprint:
     *  writer_epub.py emits title_page and log_page as DISTINCT manifest
     *  items and writes every itemref at linear="yes", so neither guard
     *  above ever saw them and the title page (title, byline, summary
     *  dump) imported as chapter 1 with the update log as chapter 2; the
     *  exact, case-insensitive match on those two idrefs counts each skip.
     *  The fingerprint is the idref convention only: a chapter whose TITLE
     *  reads "Title Page" is real prose and imports. Every spine path must
     *  name a real entry; a spine with nothing readable refuses the file. */
    private function readSpine(\DOMDocument $doc, array $manifest, array &$notes): array
    {
        $spine = [];
        $linearSkipped = 0;
        $duplicates = 0;
        $frontMatter = 0;
        foreach ($doc->getElementsByTagName('itemref') as $ref) {
            // deliberately case-insensitive (the spec is case-sensitive)
            if (mb_strtolower($ref->getAttribute('linear')) === 'no') {
                $linearSkipped++;
                continue;
            }
            $item = $manifest[$ref->getAttribute('idref')] ?? null;
            if ($item === null || !in_array($item['mediaType'], ['application/xhtml+xml', 'text/html'], true)) {
                continue;
            }
            // upstream writer_epub.py spells the idref title_page; 'titlepage'
            // stays as a legacy alias for archives built before the fix
            if (in_array(mb_strtolower($ref->getAttribute('idref')), ['title_page', 'log_page', 'titlepage'], true)) {
                $frontMatter++;
                continue;
            }
            if (in_array($item['path'], $spine, true)) {
                $duplicates++;
                continue;
            }
            $spine[] = $item['path'];
        }
        if ($linearSkipped > 0) { $notes[] = sprintf('linear="no" itemrefs skipped: %d', $linearSkipped); }
        if ($frontMatter > 0) { $notes[] = sprintf('FanFicFare front matter pages skipped: %d', $frontMatter); }
        if ($duplicates > 0) { $notes[] = sprintf('duplicate spine itemrefs skipped: %d', $duplicates); }
        if (count($spine) > self::MAX_CHAPTERS) {
            throw new \RuntimeException(sprintf('spine exceeds %d chapters: %d readable itemrefs, over the cap', self::MAX_CHAPTERS, count($spine)));
        }
        foreach ($spine as $path) {
            if ($this->entryIndex($path) === null) {
                throw new \RuntimeException(sprintf("a spine href is missing from the archive: no entry provides '%s'", $path));
            }
        }
        if ($spine === []) {
            throw new \RuntimeException('no readable chapters: the spine holds no xhtml documents');
        }
        return $spine;
    }

    /** Cover resolution: the EPUB 3 properties="cover-image" convention
     *  first, then the EPUB 2 <meta name="cover" content="itemid">
     *  convention. No convention, no cover: null is a clean answer. */
    private function findCover(\DOMDocument $doc, array $manifest): ?string
    {
        foreach ($manifest as $item) {
            if (in_array('cover-image', $item['properties'], true)) {
                return $item['path'];
            }
        }
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if ($meta->getAttribute('name') === 'cover' && isset($manifest[$meta->getAttribute('content')])) {
                return $manifest[$meta->getAttribute('content')]['path'];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // The import half. One transaction per file: story, cover, chapters,
    // the word-count SUM, then the ledger row; any throw rolls every row
    // back AND unlinks the stored cover, so a refused file stays retryable
    // and no upload orphans. The report row (lastReport) is always left
    // behind for the CLI, including on failure.
    // ------------------------------------------------------------------

    /** Import one EPUB file. $authorId names the owning member (the CLI
     *  resolves --author-id/--author to an id upstream); null falls back to
     *  an exact case-insensitive penname match on the OPF's dc:creator, and
     *  no match refuses. $commit = false is a dry run: everything is read,
     *  converted and counted into the report, nothing is written. inspect()
     *  runs INSIDE the try, so every throw (a corrupt archive included)
     *  leaves THIS file's failed report row behind; a stale row from the
     *  previous file in a batch would let the CLI print an imported line
     *  for a book that never opened. */
    public function importFile(string $path, ?int $authorId, bool $commit): void
    {
        $row = [
            'file' => $path,
            'status' => $commit ? 'imported' : 'would import',
            'title' => '',
            'chapters' => 0,
            'words' => 0,
            'storyId' => null,
            'notes' => [],
        ];
        try {
            $meta = $this->inspect($path);
            $row['title'] = (string) $meta['title'];
            foreach ($meta['spineNotes'] as $note) { $row['notes'][] = $note; }
            $digest = hash_file('sha256', $path);
            if ($digest === false) {
                throw new \RuntimeException("the file vanished before its digest was taken: {$path}");
            }
            // decision 8: the digest check runs in BOTH modes, so a
            // re-offered file skips before any author or content work in
            // commit mode and reports would-skip instead of would-import in
            // a dry run (finding 4: the old $commit guard had the dry run
            // promising an import a commit would refuse)
            if (($hit = $this->db->one('SELECT story_id FROM epub_imports WHERE sha256 = ?', [$digest])) !== null) {
                $row['status'] = $commit ? 'skipped' : 'would skip';
                $row['storyId'] = (int) $hit['story_id'];
                $row['notes'][] = sprintf('%s (already imported as story %d)',
                    $commit ? 'skipped' : 'would skip', (int) $hit['story_id']);
                $this->lastReport = $row;
                return;
            }
            $authorId = $this->resolveAuthor($authorId, (string) $meta['creator']);
            $imgCount = 0;
            $converted = $this->convertChapters($meta['spine'], $imgCount);
            if ($converted['chapters'] === []) {
                throw new \RuntimeException('no readable chapters: every spine document converted to empty markdown');
            }
            foreach ($converted['skipped'] as $note) { $row['notes'][] = $note; }
            if ($imgCount > 0) { $row['notes'][] = "<img> elements stripped: {$imgCount}"; }
            $row['chapters'] = count($converted['chapters']);
            $row['words'] = array_sum(array_column($converted['chapters'], 'words'));
            if (!$commit) {
                $this->lastReport = $row;
                return;
            }
            $this->persist($meta, $authorId, $digest, $converted['chapters'], $row);
        } catch (\Throwable $e) {
            $row['status'] = 'failed';
            $row['notes'][] = $e->getMessage();
            $this->lastReport = $row;
            throw $e;
        }
    }

    /** The committed write path, one transaction (the Importer.php:463
     *  story list plus language, the :536 chapter list with empty notes,
     *  the :547 SUM update, then the ledger row). The cover stores INSIDE
     *  the transaction and the catch unlinks it, so a rollback cannot
     *  orphan an upload (finding 4). */
    private function persist(array $meta, int $authorId, string $digest, array $chapters, array &$row): void
    {
        $title = (string) $meta['title'];
        $slug = \App\Slug::unique(
            fn (string $x): bool => $this->db->one('SELECT id FROM stories WHERE slug = ?', [$x]) !== null,
            \App\Slug::make($title, 'story'));
        $rating = $this->db->one('SELECT id FROM ratings ORDER BY position, id LIMIT 1');
        if ($rating === null) {
            throw new \RuntimeException('no ratings exist to assign: the taxonomy is missing, run the seeder first');
        }
        $now = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        $this->db->begin();
        $coverPath = null;
        try {
            // the cover stores first, inside the open transaction, so the
            // story INSERT below can carry cover_path as a plain column
            $coverPath = $this->storeCover($meta['cover'], $row);
            $this->db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, completed, featured, validated, round_robin, created_at, updated_at, is_restricted, language, cover_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$title, $slug, (string) $meta['summary'], '', $authorId, (int) $rating['id'],
                 0, 0, 1, 0, $now, $now, 0, $this->resolveLanguage((string) $meta['language']), $coverPath]);
            $storyId = (int) $this->db->lastInsertId();
            foreach ($chapters as $i => $ch) {
                $this->db->query('INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$storyId, $i + 1, $ch['title'], '', $ch['md'], '', 1, $ch['words'], $now, $now]);
            }
            // story word_count is the sum of its chapters' recomputed counts,
            // scoped to THIS story: the unscoped form is a migration-time idiom
            // and here would rewrite every story row once per imported book
            $this->db->query('UPDATE stories SET word_count = (SELECT COALESCE(SUM(word_count), 0) FROM chapters WHERE story_id = stories.id) WHERE stories.id = ?', [$storyId]);
            $this->db->query('INSERT INTO epub_imports (sha256, story_id, imported_at) VALUES (?, ?, ?)', [$digest, $storyId, $now]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($coverPath !== null) {
                @unlink(rtrim($this->uploadsDir, '/') . '/' . basename($coverPath));
            }
            throw $e;
        }
        $row['status'] = 'imported';
        $row['storyId'] = $storyId;
        $this->lastReport = $row;
    }

    /** Decision 4: an explicit id must exist; null matches dc:creator
     *  against penname exactly and case-insensitively (mb_strtolower, so
     *  pennames outside ASCII still match). No user creation, ever. */
    private function resolveAuthor(?int $authorId, string $creator): int
    {
        if ($authorId !== null) {
            if ($this->db->one('SELECT id FROM users WHERE id = ?', [$authorId]) === null) {
                throw new \RuntimeException(sprintf('no member carries author id %d: resolve --author-id against users first', $authorId));
            }
            return $authorId;
        }
        foreach ($this->pennameIndex ??= $this->buildPennameIndex() as $pen => $id) {
            if ($pen === mb_strtolower($creator)) {
                return $id;
            }
        }
        throw new \RuntimeException(sprintf("no member matches the EPUB creator '%s': pass --author or --author-id", $creator));
    }

    /** The case-folded penname index, first id winning on duplicates (the
     *  foreach-first-match order the direct scan used). */
    private function buildPennameIndex(): array
    {
        $index = [];
        foreach ($this->db->all('SELECT id, penname FROM users') as $u) {
            $index[mb_strtolower((string) $u['penname'])] ??= (int) $u['id'];
        }
        return $index;
    }

    /** Decision 5: dc:language rides the INSERT when it names an installed
     *  language pack; anything else takes the app default (Lang's own
     *  fallback, en). */
    private function resolveLanguage(string $language): string
    {
        return in_array($language, \App\Lang::installed(), true) ? $language : 'en';
    }

    /** Decision 7: every spine document read, <img> elements stripped with
     *  each removal counted, then HtmlToMarkdown. Title: first h1-h3 on the
     *  raw document, tags off, 255 chars, else "Chapter N" at the position
     *  the chapter lands on. Two scope trims keep prose clean: the document
     *  converts from <body> onward (the head's <title> is chrome, not
     *  prose), and the heading the title came from is removed (the shipped
     *  converter renders unknown elements as bare text, which would glue
     *  the title onto the first paragraph's first word). A chapter
     *  converting to whitespace is skipped with a note; the caller refuses
     *  when nothing survives. */
    private function convertChapters(array $spine, int &$imgCount): array
    {
        $chapters = [];
        $skipped = [];
        foreach ($spine as $path) {
            $idx = $this->entryIndex($path);
            if ($idx === null) {
                throw new \RuntimeException(sprintf("a spine href is missing from the archive: no entry provides '%s'", $path));
            }
            $n = count($chapters) + count($skipped) + 1;
            $xhtml = $this->readEntry($idx);
            $title = '';
            if (preg_match('~<h[1-3][^>]*>(.*?)</h[1-3]>~is', $xhtml, $m)) {
                $title = trim(strip_tags($m[1]));
                $xhtml = (string) preg_replace('~<h[1-3][^>]*>.*?</h[1-3]>~is', '', $xhtml, 1);
            }
            if (preg_match('~<body[^>]*>(.*)</body>~is', $xhtml, $b)) {
                $xhtml = $b[1];
            }
            $stripped = preg_replace('~<img\b[^>]*>~i', '', $xhtml, -1, $count);
            $imgCount += $count;
            $md = rtrim(HtmlToMarkdown::convert($stripped ?? ''));
            if (trim($md) === '') {
                $skipped[] = sprintf('chapter %d skipped: empty after conversion', $n);
                continue;
            }
            if ($title === '') { $title = 'Chapter ' . (count($chapters) + 1); }
            $chapters[] = ['title' => mb_substr($title, 0, 255), 'md' => $md, 'words' => \App\Markdown::wordCount($md)];
        }
        return ['chapters' => $chapters, 'skipped' => $skipped];
    }

    /** Decision 6: the resolved cover goes through Storage (2 MiB, image
     *  extensions, copy mover) fed a $_FILES-shaped array from the extracted
     *  temp file. No convention: clean, no cover. Oversize or non-image:
     *  a note, or a throw under strict mode (test seam). The extracted temp
     *  file always cleans up; the STORED file is the caller's rollback
     *  problem, tracked via the returned path. */
    private function storeCover(mixed $archivePath, array &$row): ?string
    {
        if ($archivePath === null) { return null; }
        $archivePath = (string) $archivePath;
        $skip = static function (string $reason) use (&$row, $archivePath): ?string {
            $row['notes'][] = "cover skipped: {$reason}";
            return null;
        };
        $idx = $this->entryIndex($archivePath);
        if ($idx === null) {
            $reason = sprintf("no entry provides '%s'", $archivePath);
            if ($this->coverStrict) { throw new \RuntimeException($reason); }
            return $skip($reason);
        }
        $bytes = $this->readEntry($idx);
        $tmp = tempnam(sys_get_temp_dir(), 'kiption-epub-cover-');
        if ($tmp === false) {
            throw new \RuntimeException('could not create a temp file for the cover extract');
        }
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new \RuntimeException("could not write the cover extract for '{$archivePath}'");
            }
            try {
                return (new \Kip\Storage($this->uploadsDir, 2 * 1024 * 1024, ['png', 'jpg', 'jpeg', 'webp', 'gif'],
                    static fn (string $t, string $d): bool => copy($t, $d) && unlink($t)))
                    ->put(['name' => basename($archivePath), 'type' => 'application/octet-stream',
                           'tmp_name' => $tmp, 'error' => \UPLOAD_ERR_OK, 'size' => strlen($bytes)]);
            } catch (\Kip\UploadException $e) {
                if ($this->coverStrict) { throw $e; }
                return $skip($e->getMessage());
            }
        } finally {
            @unlink($tmp); // the copy mover already unlinked it on success
        }
    }
}
