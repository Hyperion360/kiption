<?php // app/src/Import/Importer.php
namespace App\Import;
use Kip\Database;

/** Orchestration: BundleReader rows in, batched transactional inserts out.
 *  ONE transaction wraps the whole pass (dry-run rolls it back; commit applies
 *  it atomically; nested begin() would fatal, so "batch" below means a
 *  checkpoint statement flush every BATCH rows, NOT a transaction boundary).
 *  Resume is idempotent re-running: every legacy row consults import_map
 *  SELECT-first and skips when mapped. The mapper owns the row shapes; this
 *  class owns persistence, the content pipeline, and the report. */
final class Importer
{
    private const BATCH = 500;

    public function __construct(
        private Database $db,
        private BundleReader $reader,
        private string $mode,            // 'dry-run' | 'commit'
        private string $encoding = 'auto',
        private bool $allowMissingText = false,
        private array $config = [],      // bin/kip's loaded config (dsn, static_cache dir, ...)
    ) {}

    private Report $report;
    private string $charsetMode = 'utf8';
    private int $runId = 0;
    private int $inserted = 0;
    private int $sinceFlush = 0;
    /** @var array<string, int> */
    private array $seen = [];
    /** @var array<string, int> */
    private array $rejected = [];
    /** @var array<string, int> */
    private array $skipped = [];
    /** @var array<string, int> our-table insert tallies for the report */
    private array $counts = [];
    private int $wcLegacy = 0;
    private int $wcRecomputed = 0;
    private int $unratedCreated = 0;
    /** @var array<int, int> */
    private array $isopenSeen = [];
    /** @var string[] */
    private array $notes = [];
    /** @var array<int, int> legacy id -> new id, per table */
    private array $catMap = [];
    private array $tagTypeMap = [];
    private array $tagMap = [];
    private array $charMap = [];
    private array $ratingMap = [];
    private array $userMap = [];
    private array $storyMap = [];
    private array $chapterMap = [];
    private array $seriesMap = [];
    private array $newsMap = [];
    /** @var array<int, array{created: string, updated: string, fallback: bool}> keyed by new story id */
    private array $storyMeta = [];
    /** @var array<int, string[]> review created_at values per new story id */
    private array $reviewDates = [];

    public function run(): string
    {
        $manifest = $this->reader->manifest();
        // Mode is deliberately NOT in the hash: a post-commit dry-run must stay
        // legal (the runbook re-dry-runs); only data-affecting options gate.
        $optionsHash = sha1(json_encode(['encoding' => $this->encoding, 'allowMissingText' => $this->allowMissingText]));
        $this->assertMigrated();
        $this->assertOptionsCompatible($optionsHash);
        $this->report = new Report();
        $this->charsetMode = $this->detectCharset();
        $sync = null;
        if ($this->mode === 'commit') {
            $snapshot = dirname($this->reader->bundlePath()) . '/pre-import-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            $this->db->query('VACUUM INTO ?', [$snapshot]); // bound param: Database has no quote()
            $sync = (int) $this->db->one('PRAGMA synchronous')['synchronous'];
            $this->db->exec('PRAGMA synchronous = OFF');
        }
        $this->db->begin();
        try {
            $this->runId = $this->newRun($optionsHash);
            $this->pass($manifest);
            if ($this->mode === 'dry-run') {
                $this->db->rollBack(); // zero residue; the report counts are real
                return $this->renderReport() . "dry-run: nothing written\n";
            }
            $this->db->commit();
            if ($sync !== null) $this->db->exec("PRAGMA synchronous = {$sync}");
            $this->finishRun();
            (new LegacyRedirects())->writeForImport($this->db);
            \App\StaticCache\Builder::build($this->config, $this->config['static_cache']['dir'] ?? dirname(__DIR__, 3) . '/public/cache');
            return $this->renderReport() . $this->verifyDiff($manifest)
                . "snapshot: see pre-import-*.sqlite next to the bundle; restore = copy it over the DB\n";
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function renderReport(): string
    {
        foreach ($this->counts as $table => $n) { $this->report->table($table, $n); }
        $extra = $this->notes !== [] ? "\n" . implode("\n", $this->notes) . "\n" : '';
        return $this->report->render() . $extra;
    }

    // ------------------------------------------------------------------
    // The single mapping+insert pass both modes share. Table order matters:
    // taxonomy before stories, users before everything referencing them.
    // Each sub-pass streams its own tables off the bundle (the archive's own
    // table order is authors-first, which cannot satisfy this dependency
    // order in one sweep, so each sub-pass filters its own rows).
    // ------------------------------------------------------------------
    private function pass(array $manifest): void
    {
        // 1. taxonomy (categories, classtypes/classes, characters, ratings)
        $this->passTaxonomy();
        // 2. users + prefs + EAV bio + admins
        $this->passUsers((string) ($manifest['settings']['admins'] ?? ''));
        // 3. stories + junctions + page_stats baselines
        $this->passStories();
        // 4. chapters through the content pipeline
        $this->passChapters((string) ($manifest['settings']['store'] ?? 'db'));
        // 5. series + items + coauthors
        $this->passSeries();
        // 6. reviews, then the zero-date story re-dating (reviews are the last
        //    date-bearing table, so the fallback resolves here)
        $this->passReviews();
        // 7. favorites (ST/SE/AU)
        $this->passFavorites();
        // 8. news + comments + log
        $this->passNewsAndLog();
    }

    /** Sub-pass 1: taxonomy. Categories (parentcatid -1 -> NULL, displayorder
     *  -> position, locked, leveldown dropped with count), classtypes/classes
     *  (order from ids), characters (catid FK via the category map), ratings
     *  (ratingwarning '1' or non-empty warningtext -> is_adult 1). When the
     *  bundle imports ZERO ratings, one 'Unrated' row is created so
     *  stories.rating_id NOT NULL can always resolve (review finding 3). */
    private function passTaxonomy(): void
    {
        $r = $this->report;
        $takenTypeNames = [];
        foreach ($this->db->all('SELECT name FROM tag_types') as $t) { $takenTypeNames[] = (string) $t['name']; }
        foreach ($this->reader->rows() as $e) {
            $row = $e['row'];
            switch ($e['table']) {
                case 'fanfiction_categories':
                    $this->bump($this->seen, 'fanfiction_categories');
                    $lid = (string) $row['catid'];
                    if (($id = $this->mappedId('fanfiction_categories', $lid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_categories');
                        $this->catMap[(int) $lid] = $id;
                        break;
                    }
                    $parent = (int) ($row['parentcatid'] ?? -1);
                    $name = mb_substr(trim((string) $row['category']), 0, 255);
                    $slug = \App\Slug::unique(
                        fn (string $s): bool => $this->db->one('SELECT id FROM categories WHERE slug = ?', [$s]) !== null,
                        \App\Slug::make($name, 'category'));
                    $this->db->query('INSERT INTO categories (parent_id, name, slug, description, locked, position) VALUES (?, ?, ?, ?, ?, ?)',
                        [$parent > 0 && isset($this->catMap[$parent]) ? $this->catMap[$parent] : null,
                         $name, $slug, $this->prose((string) ($row['description'] ?? '')),
                         ($row['locked'] ?? '0') === '1' ? 1 : 0, (int) ($row['displayorder'] ?? 0)]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_categories', $lid, 'categories', $id);
                    $this->catMap[(int) $lid] = $id;
                    if ((int) ($row['leveldown'] ?? 0) > 0) $r->drop('category leveldown');
                    $this->bump($this->counts, 'categories');
                    $this->tick();
                    break;
                case 'fanfiction_classtypes':
                    $this->bump($this->seen, 'fanfiction_classtypes');
                    $lid = (string) $row['classtype_id'];
                    if (($id = $this->mappedId('fanfiction_classtypes', $lid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_classtypes');
                        $this->tagTypeMap[(int) $lid] = $id;
                        break;
                    }
                    $name = mb_substr(trim((string) $row['classtype_name']), 0, 255);
                    $base = $name;
                    for ($n = 2; in_array($name, $takenTypeNames, true); $n++) { $name = $base . '-' . $n; }
                    $takenTypeNames[] = $name;
                    $this->db->query('INSERT INTO tag_types (name) VALUES (?)', [$name]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_classtypes', $lid, 'tag_types', $id);
                    $this->tagTypeMap[(int) $lid] = $id;
                    $this->bump($this->counts, 'tag_types');
                    $this->tick();
                    break;
                case 'fanfiction_classes':
                    $this->bump($this->seen, 'fanfiction_classes');
                    $lid = (string) $row['class_id'];
                    if (($id = $this->mappedId('fanfiction_classes', $lid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_classes');
                        $this->tagMap[(int) $lid] = $id;
                        break;
                    }
                    $typeId = $this->tagTypeMap[(int) ($row['class_type'] ?? 0)] ?? null;
                    if ($typeId === null) {
                        $r->reject('class type missing'); $this->bump($this->rejected, 'fanfiction_classes');
                        break;
                    }
                    $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)',
                        [$typeId, mb_substr(trim((string) $row['class_name']), 0, 255)]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_classes', $lid, 'tags', $id);
                    $this->tagMap[(int) $lid] = $id;
                    $this->bump($this->counts, 'tags');
                    $this->tick();
                    break;
                case 'fanfiction_characters':
                    $this->bump($this->seen, 'fanfiction_characters');
                    $lid = (string) $row['charid'];
                    if (($id = $this->mappedId('fanfiction_characters', $lid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_characters');
                        $this->charMap[(int) $lid] = $id;
                        break;
                    }
                    $categoryId = $this->catMap[(int) ($row['catid'] ?? 0)] ?? null;
                    if ($categoryId === null) {
                        $r->reject('character category missing'); $this->bump($this->rejected, 'fanfiction_characters');
                        break;
                    }
                    $name = mb_substr(trim((string) $row['charname']), 0, 255);
                    $slug = \App\Slug::unique(
                        fn (string $s): bool => $this->db->one('SELECT id FROM characters WHERE slug = ?', [$s]) !== null,
                        \App\Slug::make($name, 'character'));
                    $this->db->query('INSERT INTO characters (category_id, name, slug, description) VALUES (?, ?, ?, ?)',
                        [$categoryId, $name, $slug, $this->prose((string) ($row['bio'] ?? ''))]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_characters', $lid, 'characters', $id);
                    $this->charMap[(int) $lid] = $id;
                    $this->bump($this->counts, 'characters');
                    $this->tick();
                    break;
                case 'fanfiction_ratings':
                    $this->bump($this->seen, 'fanfiction_ratings');
                    $lid = (string) $row['rid'];
                    if (($id = $this->mappedId('fanfiction_ratings', $lid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_ratings');
                        $this->ratingMap[(int) $lid] = $id;
                        break;
                    }
                    $warning = trim((string) ($row['warningtext'] ?? ''));
                    $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, ?, ?, ?)',
                        [mb_substr(trim((string) $row['rating']), 0, 255),
                         (($row['ratingwarning'] ?? '0') === '1' || $warning !== '') ? 1 : 0,
                         $warning, count($this->ratingMap)]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_ratings', $lid, 'ratings', $id);
                    $this->ratingMap[(int) $lid] = $id;
                    $this->bump($this->counts, 'ratings');
                    $this->tick();
                    break;
            }
        }
        if ($this->tally($this->counts, 'ratings') === 0 && $this->tally($this->skipped, 'fanfiction_ratings') === 0) {
            $this->unratedId(); // empty taxonomy (nothing imported, nothing resumed): guarantee the fallback
        }
    }

    /** The 'Unrated' fallback rating id, creating the row on demand: a story
     *  whose rid CSV resolves nothing (rid 0, or a rating deleted from the old
     *  install) still needs a resolvable rating_id. Creation counts as a
     *  fallback row in the report and the verification diff. */
    private function unratedId(): int
    {
        $row = $this->db->one("SELECT id FROM ratings WHERE label = 'Unrated'");
        if ($row === null) {
            $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, ?, ?)',
                ['Unrated', '', (int) $this->db->one('SELECT COUNT(*) c FROM ratings')['c']]);
            $row = ['id' => $this->db->lastInsertId()];
            $this->bump($this->counts, 'ratings');
            $this->unratedCreated++;
        }
        return (int) $row['id'];
    }

    /** Sub-pass 2: users. Authorprefs indexed by uid; EAV: authorfields named
     *  'bio' land in users.bio (EAV wins over the authors.bio column), every
     *  other field counted as dropped; unguessable password_hash on every
     *  import (the md5 login hook owns first login, finding 8); prefs via
     *  mapPrefs; profile slugs via backfillProfileSlug; the manifest's admins
     *  CSV upgrades roles; ageconsent -> age_consented_at. eFiction 3.5.5
     *  authors carry no lock-state column: recorded in the report, no-op. */
    private function passUsers(string $adminsCsv): void
    {
        $r = $this->report;
        $authors = []; $prefs = []; $fieldNames = []; $infoRows = [];
        foreach ($this->reader->rows() as $e) {
            $row = $e['row'];
            switch ($e['table']) {
                case 'fanfiction_authors': $authors[] = $row; break;
                case 'fanfiction_authorprefs': $prefs[(int) $row['uid']] = $row; break;
                case 'fanfiction_authorfields': $fieldNames[(int) $row['field_id']] = (string) $row['field_name']; break;
                case 'fanfiction_authorinfo': $infoRows[] = $row; break;
            }
        }
        $eav = [];
        foreach ($infoRows as $i) {
            $name = $fieldNames[(int) $i['field_id']] ?? ('field ' . $i['field_id']);
            if (mb_strtolower($name) === 'bio') { $eav[(int) $i['uid']] = (string) $i['info']; }
            else { $r->drop('authorinfo field ' . $name); }
        }
        // Collision context grows as '|a|b|' (leading AND trailing pipes, the
        // probe-verified form) and is seeded from users already in the DB: a
        // fresh import honors pre-existing members, a resume honors the prior
        // run's rows.
        $takenPennames = [];
        $takenEmails = '';
        foreach ($this->db->all('SELECT penname, email FROM users') as $u) {
            if ($u['penname'] !== null) $takenPennames[] = (string) $u['penname'];
            $takenEmails .= '|' . strtolower((string) $u['email']) . '|';
        }
        foreach ($authors as $a) {
            $this->bump($this->seen, 'fanfiction_authors');
            $uid = (int) $a['uid'];
            if (($id = $this->mappedId('fanfiction_authors', (string) $uid)) !== null) {
                $r->alreadyMapped++;
                $this->bump($this->skipped, 'fanfiction_authors');
                $this->bump($this->skipped, 'fanfiction_authorprefs');
                $this->userMap[$uid] = $id;
                continue;
            }
            $u = EfictionMapper::mapUser($a, $prefs[$uid] ?? null, $takenPennames, $takenEmails, $r);
            if ($u === null) { $this->bump($this->rejected, 'fanfiction_authors'); continue; }
            if (isset($eav[$uid])) $u['bio'] = $eav[$uid]; // EAV wins over the column
            $p = $prefs[$uid] ?? null;
            $this->db->query('INSERT INTO users (email, password_hash, penname, legacy_md5, role, bio, email_verified_at, approved_at, created_at, age_consented_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$u['email'],
                 password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), // unguessable; the md5 hook owns first login
                 $u['penname'], $u['legacy_md5'], $u['role'],
                 $this->prose((string) $u['bio']),
                 $u['email_verified_at'], $u['approved_at'], $u['created_at'],
                 self::dateOrNull($p['ageconsent'] ?? null)]);
            $id = (int) $this->db->lastInsertId();
            (new \App\Repositories\UserRepository($this->db))->backfillProfileSlug($id);
            $this->record('fanfiction_authors', (string) $uid, 'users', $id);
            $this->userMap[$uid] = $id;
            $takenPennames[] = $u['penname'];
            $takenEmails .= '|' . $u['email'] . '|';
            $this->bump($this->counts, 'users');
            $this->tick();
            $kp = EfictionMapper::mapPrefs($p);
            $this->db->query('INSERT INTO user_prefs (user_id, notify_review, notify_response, notify_favorites, notify_favorite_digest, default_sort, toc_first) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $kp['notify_review'], $kp['notify_response'], $kp['notify_favorites'],
                 $kp['notify_favorite_digest'], $kp['default_sort'], $kp['toc_first']]);
            $this->record('fanfiction_authorprefs', (string) $uid, 'user_prefs', $id);
            $this->bump($this->counts, 'user_prefs');
            $this->tick();
        }
        $this->seen['fanfiction_authorprefs'] = count($prefs);
        $upgraded = 0;
        foreach (preg_split('/[,;]/', $adminsCsv) ?: [] as $part) {
            $aid = (int) trim($part);
            if ($aid > 0 && isset($this->userMap[$aid])) {
                $this->db->query("UPDATE users SET role = 'admin', is_admin = 1 WHERE id = ?", [$this->userMap[$aid]]);
                $upgraded++;
            }
        }
        $this->notes[] = "admins upgraded from the manifest CSV: {$upgraded}";
        $this->notes[] = 'author lock states: eFiction 3.5.5 authors carry no lock column (no-op)';
    }

    /** Sub-pass 3: stories. mapStory with the taxonomy id maps; rating falls
     *  back to 'Unrated' when the CSV token resolves nothing (counted);
     *  created_fallback rows are re-dated after sub-pass 6; junction rows from
     *  the CSV-resolved arrays; page_stats baseline rows carry legacy_reads on
     *  the import day (labeled in the report). */
    private function passStories(): void
    {
        $r = $this->report;
        $ctx = ['categories' => $this->catMap, 'ratings' => $this->ratingMap, 'classes' => $this->tagMap, 'characters' => $this->charMap];
        $day = gmdate('Y-m-d');
        foreach ($this->reader->rows() as $e) {
            if ($e['table'] !== 'fanfiction_stories') continue;
            $row = $e['row'];
            $this->bump($this->seen, 'fanfiction_stories');
            $sid = (int) $row['sid'];
            if (($id = $this->mappedId('fanfiction_stories', (string) $sid)) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_stories');
                $this->storyMap[$sid] = $id;
                continue;
            }
            $s = EfictionMapper::mapStory($row, $ctx, $r);
            $authorId = $this->userMap[(int) $row['uid']] ?? null;
            if ($authorId === null) { $r->reject('story author missing'); $this->bump($this->rejected, 'fanfiction_stories'); continue; }
            $ratingId = $s['rating_label'];
            if ($ratingId === null) {
                $ratingId = $this->unratedId();
                $r->drop('rating fallback: Unrated');
            }
            $slug = \App\Slug::unique(
                fn (string $x): bool => $this->db->one('SELECT id FROM stories WHERE slug = ?', [$x]) !== null,
                \App\Slug::make($s['title'], 'story'));
            $created = $s['created_at'] ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM);
            $this->db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, completed, featured, validated, round_robin, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$s['title'], $slug, $this->prose((string) $s['summary']), $this->prose((string) $s['notes']),
                 $authorId, $ratingId, $s['completed'], $s['featured'], $s['validated'], $s['round_robin'],
                 $created, $s['updated_at']]);
            $id = (int) $this->db->lastInsertId();
            $this->record('fanfiction_stories', (string) $sid, 'stories', $id);
            $this->storyMap[$sid] = $id;
            $this->storyMeta[$id] = ['created' => $created, 'updated' => $s['updated_at'], 'fallback' => $s['created_fallback']];
            foreach ($s['categories'] as $cid) {
                $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$id, $cid]);
                $this->bump($this->counts, 'story_categories'); $this->tick();
            }
            foreach ($s['tags'] as $tid) {
                $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$id, $tid]);
                $this->bump($this->counts, 'story_tags'); $this->tick();
            }
            foreach ($s['characters'] as $chid) {
                $this->db->query('INSERT INTO story_characters (story_id, character_id) VALUES (?, ?)', [$id, $chid]);
                $this->bump($this->counts, 'story_characters'); $this->tick();
            }
            if ((int) $s['legacy_reads'] > 0) {
                $this->db->query('INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (?, ?, 0, ?)',
                    [$day, $id, (int) $s['legacy_reads']]);
                $this->bump($this->counts, 'page_stats baselines'); $this->tick();
            }
            $this->bump($this->counts, 'stories');
            $this->tick();
        }
    }

    /** Sub-pass 4: chapters. store=files rows read via the reader's
     *  storyFile(chapter's OWN uid, chapid); missing -> hard reject counted,
     *  or a stub chapter with a visible notice body when --allow-missing-text.
     *  Content pipeline: Charset then HtmlToMarkdown then a
     *  Markdown::wordCount recompute (drift tallied vs the legacy sum). Title
     *  -> title, notes -> notes_before, endnotes -> notes_after; validated per
     *  chapter; position from inorder; created/updated from the story's dates
     *  as known at insert time (zero-date stories keep the provisional date on
     *  their chapters; only the story row is re-dated after sub-pass 6). */
    private function passChapters(string $store): void
    {
        $r = $this->report;
        $pos = [];
        foreach ($this->reader->rows() as $e) {
            if ($e['table'] !== 'fanfiction_chapters') continue;
            $row = $e['row'];
            $this->bump($this->seen, 'fanfiction_chapters');
            $chapid = (int) $row['chapid'];
            if (($id = $this->mappedId('fanfiction_chapters', (string) $chapid)) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_chapters');
                $this->chapterMap[$chapid] = $id;
                continue;
            }
            $storyId = $this->storyMap[(int) $row['sid']] ?? null;
            if ($storyId === null) { $r->reject('chapter story missing'); $this->bump($this->rejected, 'fanfiction_chapters'); continue; }
            if ($store === 'files') {
                $raw = $this->reader->storyFile((int) $row['uid'], $chapid);
                if ($raw === null) {
                    $r->missingStoryFiles++;
                    if (!$this->allowMissingText) { $r->reject('chapter text missing'); $this->bump($this->rejected, 'fanfiction_chapters'); continue; }
                    $raw = 'The original text file for this chapter was missing from the export bundle.';
                }
            } else {
                $raw = (string) ($row['storytext'] ?? '');
            }
            $md = $this->prose($raw);
            $wc = \App\Markdown::wordCount($md);
            $this->wcLegacy += (int) ($row['wordcount'] ?? 0);
            $this->wcRecomputed += $wc;
            $meta = $this->storyMeta[$storyId];
            $p = (int) ($row['inorder'] ?? 0);
            if ($p < 1) $p = ($pos[$storyId] ?? 0) + 1;
            $pos[$storyId] = max($p, $pos[$storyId] ?? 0);
            $this->db->query('INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$storyId, $p, mb_substr(trim((string) ($row['title'] ?? '')), 0, 255),
                 $this->prose((string) ($row['notes'] ?? '')), $md, $this->prose((string) ($row['endnotes'] ?? '')),
                 ($row['validated'] ?? '0') === '1' ? 1 : 0, $wc, $meta['created'], $meta['updated']]);
            $id = (int) $this->db->lastInsertId();
            $this->record('fanfiction_chapters', (string) $chapid, 'chapters', $id);
            $this->chapterMap[$chapid] = $id;
            $this->bump($this->counts, 'chapters');
            $this->tick();
        }
        // story word_count is the sum of its chapters' recomputed counts
        $this->db->query('UPDATE stories SET word_count = (SELECT COALESCE(SUM(word_count), 0) FROM chapters WHERE story_id = stories.id)');
    }

    /** Sub-pass 5: series + inseries + coauthors. The report prints the
     *  DISTINCT isopen values seen (computed from the rows); challenges CSVs
     *  drop with a count; coauthor rows whose uid failed import reject with a
     *  count. Series/items/coauthor rows carry composite import_map keys so a
     *  re-commit cannot double-insert against the junction unique indexes. */
    private function passSeries(): void
    {
        $r = $this->report;
        $items = []; $coauthors = [];
        foreach ($this->reader->rows() as $e) {
            $row = $e['row'];
            switch ($e['table']) {
                case 'fanfiction_series':
                    $this->bump($this->seen, 'fanfiction_series');
                    $seriesid = (int) $row['seriesid'];
                    if (($id = $this->mappedId('fanfiction_series', (string) $seriesid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_series');
                        $this->seriesMap[$seriesid] = $id;
                        break;
                    }
                    $x = EfictionMapper::mapSeries($row, $r);
                    $ownerId = $this->userMap[(int) ($row['uid'] ?? 0)] ?? null;
                    if ($ownerId === null) { $r->reject('series owner missing'); $this->bump($this->rejected, 'fanfiction_series'); break; }
                    $slug = \App\Slug::unique(
                        fn (string $s): bool => $this->db->one('SELECT id FROM series WHERE slug = ?', [$s]) !== null,
                        \App\Slug::make($x['title'], 'series'));
                    $this->db->query('INSERT INTO series (title, slug, summary, owner_id, membership) VALUES (?, ?, ?, ?, ?)',
                        [$x['title'], $slug, $this->prose((string) $x['summary']), $ownerId, $x['membership']]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_series', (string) $seriesid, 'series', $id);
                    $this->seriesMap[$seriesid] = $id;
                    $this->isopenSeen[(int) ($row['isopen'] ?? -1)] = true;
                    $challenges = trim((string) ($row['challenges'] ?? ''));
                    if ($challenges !== '' && $challenges !== '0') $r->drop('series challenges CSV');
                    $this->bump($this->counts, 'series');
                    $this->tick();
                    break;
                case 'fanfiction_inseries': $items[] = $row; break;
                case 'fanfiction_coauthors': $coauthors[] = $row; break;
            }
        }
        foreach ($items as $row) {
            $this->bump($this->seen, 'fanfiction_inseries');
            $sid = (int) $row['sid']; $seriesid = (int) $row['seriesid']; $sub = (int) ($row['subseriesid'] ?? 0);
            $lid = $seriesid . ':' . $sid . ':' . $sub;
            if ($this->mappedId('fanfiction_inseries', $lid) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_inseries');
                continue;
            }
            $seriesId = $this->seriesMap[$seriesid] ?? null;
            $storyId = $sid > 0 ? ($this->storyMap[$sid] ?? null) : null;
            $subId = $sub > 0 ? ($this->seriesMap[$sub] ?? null) : null;
            if ($seriesId === null || ($storyId === null) === ($subId === null)) {
                // missing both anchors, or a row carrying both: unsatisfiable either way
                $r->reject('series item unresolvable'); $this->bump($this->rejected, 'fanfiction_inseries');
                continue;
            }
            $this->db->query('INSERT INTO series_items (series_id, story_id, subseries_id, position, confirmed) VALUES (?, ?, ?, ?, ?)',
                [$seriesId, $storyId, $subId, (int) ($row['inorder'] ?? 0), (int) ($row['confirmed'] ?? 0) === 1 ? 1 : 0]);
            $this->record('fanfiction_inseries', $lid, 'series_items', (int) $this->db->lastInsertId());
            $this->bump($this->counts, 'series_items');
            $this->tick();
        }
        foreach ($coauthors as $row) {
            $this->bump($this->seen, 'fanfiction_coauthors');
            $sid = (int) $row['sid']; $uid = (int) $row['uid'];
            $lid = $sid . ':' . $uid;
            if ($this->mappedId('fanfiction_coauthors', $lid) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_coauthors');
                continue;
            }
            $storyId = $this->storyMap[$sid] ?? null;
            $userId = $this->userMap[$uid] ?? null;
            if ($storyId === null || $userId === null) {
                $r->reject('coauthor unresolvable'); $this->bump($this->rejected, 'fanfiction_coauthors');
                continue;
            }
            $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$storyId, $userId]);
            $this->record('fanfiction_coauthors', $lid, 'coauthors', $storyId);
            $this->bump($this->counts, 'coauthors');
            $this->tick();
        }
        if ($this->isopenSeen !== []) {
            $this->notes[] = 'series isopen values seen (2=open, 1=moderated, 0=closed): ' . implode(', ', array_keys($this->isopenSeen));
        }
    }

    /** Sub-pass 6: reviews. story/series targets resolve through the id maps
     *  (a review whose target resolved to neither rejects: the table's CHECK
     *  demands one of them); chapid anchors through the chapter map when it
     *  resolves, NULL otherwise with a count (chapid 0 is eFiction's legit
     *  story-level shape and stays NULL uncounted); user_id remaps through the
     *  user map, failed uids become NULL with a count. Afterwards, stories
     *  whose created_at fell back (zero legacy date) re-date to the earliest
     *  chapter/review date, else stay at now. */
    private function passReviews(): void
    {
        $r = $this->report;
        foreach ($this->reader->rows() as $e) {
            if ($e['table'] !== 'fanfiction_reviews') continue;
            $row = $e['row'];
            $this->bump($this->seen, 'fanfiction_reviews');
            $rid = (int) $row['reviewid'];
            if ($this->mappedId('fanfiction_reviews', (string) $rid) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_reviews');
                continue;
            }
            $v = EfictionMapper::mapReview($row, $r);
            $storyId = $v['story_item'] !== null ? ($this->storyMap[$v['story_item']] ?? null) : null;
            $seriesId = $v['series_item'] !== null ? ($this->seriesMap[$v['series_item']] ?? null) : null;
            if ($storyId === null && $seriesId === null) {
                $r->reject('review target unresolvable'); $this->bump($this->rejected, 'fanfiction_reviews');
                continue;
            }
            $chapterId = null;
            $chapid = (int) ($row['chapid'] ?? 0);
            if ($chapid > 0) {
                $chapterId = $this->chapterMap[$chapid] ?? null;
                if ($chapterId === null) $r->drop('review chapter anchor');
            }
            $userId = $v['user_id'] !== null ? ($this->userMap[$v['user_id']] ?? null) : null;
            if ($v['user_id'] !== null && $userId === null) $r->drop('review reviewer missing');
            $this->db->query('INSERT INTO reviews (story_id, series_id, chapter_id, user_id, guest_name, body, rating, response, responded_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$storyId, $seriesId, $chapterId, $userId, $v['guest_name'],
                 $v['body'] === null ? null : $this->prose($v['body']),
                 $v['rating'],
                 $v['response'] === null ? null : $this->prose($v['response']),
                 $v['responded_at'], $v['created_at']]);
            $id = (int) $this->db->lastInsertId();
            $this->record('fanfiction_reviews', (string) $rid, 'reviews', $id);
            if ($storyId !== null) $this->reviewDates[$storyId][] = $v['created_at'];
            $this->bump($this->counts, 'reviews');
            $this->tick();
        }
        $redated = 0;
        foreach ($this->storyMeta as $storyId => $meta) {
            if (!$meta['fallback']) continue;
            $dates = $this->reviewDates[$storyId] ?? [];
            foreach ($this->db->all('SELECT created_at FROM chapters WHERE story_id = ?', [$storyId]) as $c) { $dates[] = $c['created_at']; }
            sort($dates);
            if ($dates !== []) {
                $this->db->query('UPDATE stories SET created_at = ? WHERE id = ?', [$dates[0], $storyId]);
                $redated++;
            }
        }
        if ($redated > 0) $this->notes[] = "stories re-dated via earliest chapter/review date: {$redated}";
    }

    /** Sub-pass 7: favorites. ST/SE/AU -> story/series/author through the
     *  maps; failed users or targets reject with a count. Composite map key
     *  uid:item:type guards the partial unique indexes on re-commit. */
    private function passFavorites(): void
    {
        $r = $this->report;
        foreach ($this->reader->rows() as $e) {
            if ($e['table'] !== 'fanfiction_favorites') continue;
            $row = $e['row'];
            $this->bump($this->seen, 'fanfiction_favorites');
            $uid = (int) $row['uid']; $item = (int) $row['item']; $type = (string) $row['type'];
            $lid = "{$uid}:{$item}:{$type}";
            if ($this->mappedId('fanfiction_favorites', $lid) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_favorites');
                continue;
            }
            $userId = $this->userMap[$uid] ?? null;
            $target = match ($type) {
                'ST' => $this->storyMap[$item] ?? null,
                'SE' => $this->seriesMap[$item] ?? null,
                'AU' => $this->userMap[$item] ?? null,
                default => null,
            };
            if ($userId === null || $target === null) {
                $r->reject('favorite unresolvable'); $this->bump($this->rejected, 'fanfiction_favorites');
                continue;
            }
            [$storyId, $seriesId, $authorId] = match ($type) {
                'ST' => [$target, null, null],
                'SE' => [null, $target, null],
                default => [null, null, $target],
            };
            $this->db->query('INSERT INTO favorites (user_id, story_id, series_id, author_id) VALUES (?, ?, ?, ?)',
                [$userId, $storyId, $seriesId, $authorId]);
            $this->record('fanfiction_favorites', $lid, 'favorites', (int) $this->db->lastInsertId());
            if (trim((string) ($row['comments'] ?? '')) !== '') $r->drop('favorite comments');
            $this->bump($this->counts, 'favorites');
            $this->tick();
        }
    }

    /** Sub-pass 8: news + comments + log. News drops its author STRING
     *  (counted in mapNews; no string column exists) and keeps published_at;
     *  comments resolve through the nid map (failed -> rejected count) with
     *  failed uids NULL-counted; log rows land in legacy_log verbatim.
     *  messages/pagelinks stay in the bundle for Phase 10, counted here. */
    private function passNewsAndLog(): void
    {
        $r = $this->report;
        $comments = [];
        foreach ($this->reader->rows() as $e) {
            $row = $e['row'];
            switch ($e['table']) {
                case 'fanfiction_news':
                    $this->bump($this->seen, 'fanfiction_news');
                    $nid = (int) $row['nid'];
                    if (($id = $this->mappedId('fanfiction_news', (string) $nid)) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_news');
                        $this->newsMap[$nid] = $id;
                        break;
                    }
                    $n = EfictionMapper::mapNews($row, $r);
                    $this->db->query('INSERT INTO news (author_id, title, body, published_at) VALUES (?, ?, ?, ?)',
                        [null, $n['title'], $this->prose((string) $n['body']), $n['published_at']]);
                    $id = (int) $this->db->lastInsertId();
                    $this->record('fanfiction_news', (string) $nid, 'news', $id);
                    $this->newsMap[$nid] = $id;
                    $this->bump($this->counts, 'news');
                    $this->tick();
                    break;
                case 'fanfiction_comments': $comments[] = $row; break;
                case 'fanfiction_log':
                    $this->bump($this->seen, 'fanfiction_log');
                    $lid = (string) $row['log_id'];
                    if ($this->mappedId('fanfiction_log', $lid) !== null) {
                        $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_log');
                        break;
                    }
                    $this->db->query('INSERT INTO legacy_log (log_action, log_uid, log_timestamp, log_type) VALUES (?, ?, ?, ?)',
                        [$row['log_action'] ?? null,
                         ($row['log_uid'] ?? null) === null || $row['log_uid'] === '' ? null : (int) $row['log_uid'],
                         $row['log_timestamp'] ?? null, $row['log_type'] ?? null]);
                    $this->record('fanfiction_log', $lid, 'legacy_log', (int) $this->db->lastInsertId());
                    $this->bump($this->counts, 'legacy_log');
                    $this->tick();
                    break;
                case 'fanfiction_messages': $r->drop('messages (Phase 10 mail templates)'); break;
                case 'fanfiction_pagelinks': $r->drop('pagelinks (Phase 10 nav)'); break;
            }
        }
        foreach ($comments as $row) {
            $this->bump($this->seen, 'fanfiction_comments');
            $cid = (string) $row['cid'];
            if ($this->mappedId('fanfiction_comments', $cid) !== null) {
                $r->alreadyMapped++; $this->bump($this->skipped, 'fanfiction_comments');
                continue;
            }
            $newsId = $this->newsMap[(int) $row['nid']] ?? null;
            if ($newsId === null) { $r->reject('news comment target missing'); $this->bump($this->rejected, 'fanfiction_comments'); continue; }
            $uid = (int) ($row['uid'] ?? 0);
            $userId = $uid > 0 ? ($this->userMap[$uid] ?? null) : null;
            if ($uid > 0 && $userId === null) $r->drop('news comment author missing');
            $this->db->query('INSERT INTO news_comments (news_id, user_id, body, created_at) VALUES (?, ?, ?, ?)',
                [$newsId, $userId, $this->prose((string) ($row['comment'] ?? '')),
                 self::dateOrNull($row['time'] ?? null) ?? (new \DateTimeImmutable('now'))->format(DATE_ATOM)]);
            $this->record('fanfiction_comments', $cid, 'news_comments', (int) $this->db->lastInsertId());
            $this->bump($this->counts, 'news_comments');
            $this->tick();
        }
    }

    /** Recomputed row counts per table vs the manifest's legacy counts minus
     *  rejects, plus the wordcount drift (finding 15). */
    private function verifyDiff(array $manifest): string
    {
        $lines = ['verification', str_repeat('-', 13)];
        $ours = [
            'fanfiction_authors' => 'users', 'fanfiction_authorprefs' => 'user_prefs',
            'fanfiction_categories' => 'categories', 'fanfiction_classtypes' => 'tag_types',
            'fanfiction_classes' => 'tags', 'fanfiction_characters' => 'characters',
            'fanfiction_ratings' => 'ratings', 'fanfiction_stories' => 'stories',
            'fanfiction_chapters' => 'chapters', 'fanfiction_series' => 'series',
            'fanfiction_inseries' => 'series_items', 'fanfiction_coauthors' => 'coauthors',
            'fanfiction_reviews' => 'reviews', 'fanfiction_favorites' => 'favorites',
            'fanfiction_news' => 'news', 'fanfiction_comments' => 'news_comments',
            'fanfiction_log' => 'legacy_log',
        ];
        foreach ($ours as $legacy => $newTable) {
            $m = (int) ($manifest['counts'][$legacy] ?? 0);
            $imported = $this->tally($this->counts, $newTable);
            $rej = $this->tally($this->rejected, $legacy);
            $skip = $this->tally($this->skipped, $legacy);
            if ($m === 0 && $imported === 0 && $rej === 0 && $skip === 0) continue;
            $note = '';
            if ($legacy === 'fanfiction_authorprefs') {
                // every imported author gets a prefs row; authors without a
                // legacy prefs row import mapPrefs defaults
                $note = ' (defaults fill authors with no legacy prefs row)';
            } elseif ($legacy === 'fanfiction_ratings' && $this->unratedCreated > 0
                && $imported === $m + $rej + $skip + $this->unratedCreated) {
                $note = " (+{$this->unratedCreated} Unrated fallback)";
            } elseif ($imported + $rej + $skip !== $m) {
                $note = ' MISMATCH';
            }
            $lines[] = sprintf('%-26s manifest %d, imported %d, rejected %d, skipped %d%s', $legacy, $m, $imported, $rej, $skip, $note);
        }
        $lines[] = sprintf('wordcounts: recomputed %d vs legacy %d (drift %+d)',
            $this->wcRecomputed, $this->wcLegacy, $this->wcRecomputed - $this->wcLegacy);
        return implode("\n", $lines) . "\n\n";
    }

    /** Every stored prose value runs Charset then HtmlToMarkdown; the uniform
     *  trailing newline is trimmed so extracted response text stays verbatim. */
    private function prose(string $raw): string
    {
        return rtrim(HtmlToMarkdown::convert(Charset::toUtf8($raw, $this->charsetMode, $this->report)));
    }

    /** Sampled heuristic over the first 100 prose values in the bundle when
     *  --encoding=auto; an explicit override wins. */
    private function detectCharset(): string
    {
        $fields = [
            'fanfiction_authors' => ['bio'],
            'fanfiction_authorinfo' => ['info'],
            'fanfiction_stories' => ['summary', 'storynotes'],
            'fanfiction_chapters' => ['storytext', 'notes', 'endnotes'],
            'fanfiction_reviews' => ['review'],
            'fanfiction_series' => ['summary'],
            'fanfiction_news' => ['story'],
            'fanfiction_comments' => ['comment'],
        ];
        $samples = [];
        foreach ($this->reader->rows() as $e) {
            foreach ($fields[$e['table']] ?? [] as $key) {
                $v = (string) ($e['row'][$key] ?? '');
                if ($v !== '') {
                    $samples[] = $v;
                    if (count($samples) >= 100) break 2;
                }
            }
        }
        return Charset::detect($samples, $this->encoding);
    }

    /** Checkpoint flush: BATCH inserts per UPDATE of import_runs.checkpoints
     *  (a flush boundary inside the single transaction, not a commit). */
    private function tick(): void
    {
        $this->inserted++;
        if (++$this->sinceFlush >= self::BATCH) {
            $this->db->query('UPDATE import_runs SET checkpoints = ? WHERE id = ?',
                [json_encode(['inserted' => $this->inserted, 'at' => gmdate('Y-m-d\TH:i:s\Z')]), $this->runId]);
            $this->sinceFlush = 0;
        }
    }

    private function mappedId(string $legacyTable, string $legacyId): ?int
    {
        $row = $this->db->one('SELECT new_id FROM import_map WHERE legacy_table = ? AND legacy_id = ?', [$legacyTable, $legacyId]);
        return $row === null ? null : (int) $row['new_id'];
    }

    /** Read a per-table tally (missing key reads as zero). */
    private function tally(array $t, string $key): int
    {
        return $t[$key] ?? 0;
    }

    /** Increment a per-table tally without relying on key auto-vivification. */
    private function bump(array &$t, string $key, int $n = 1): void
    {
        $t[$key] = ($t[$key] ?? 0) + $n;
    }

    private function record(string $legacyTable, string $legacyId, string $newTable, int $newId): void
    {
        $this->db->query('INSERT INTO import_map (legacy_table, legacy_id, new_table, new_id, run_id) VALUES (?, ?, ?, ?, ?)',
            [$legacyTable, $legacyId, $newTable, $newId, $this->runId]);
    }

    private function assertMigrated(): void
    {
        try { $this->db->one('SELECT 1 FROM import_map LIMIT 1'); }
        catch (\PDOException) { throw new \RuntimeException('run `kip migrate` first'); }
    }

    private function assertOptionsCompatible(string $hash): void
    {
        $row = $this->db->one('SELECT options_hash FROM import_runs WHERE status = \'committed\' ORDER BY id DESC LIMIT 1');
        if ($row !== null && $row['options_hash'] !== $hash
            && (int) $this->db->one('SELECT COUNT(*) c FROM import_map')['c'] > 0) {
            throw new \RuntimeException('this database already has imports under different options; refusing to mix');
        }
    }

    private function newRun(string $hash): int
    {
        $this->db->query('INSERT INTO import_runs (options_hash) VALUES (?)', [$hash]);
        return (int) $this->db->lastInsertId();
    }

    private function finishRun(): void
    {
        $this->db->query('UPDATE import_runs SET status = \'committed\', finished_at = ? WHERE id = ?',
            [gmdate('Y-m-d\TH:i:s\Z'), $this->runId]);
    }

    /** Legacy MySQL datetime or null; anything unparseable drops to null
     *  (ageconsent flags like '1' have no date meaning to carry). */
    private static function dateOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) return null;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($value))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        return $d === false ? null : $d->format(DATE_ATOM);
    }
}
