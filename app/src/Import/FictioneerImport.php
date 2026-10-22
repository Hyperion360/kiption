<?php // app/src/Import/FictioneerImport.php
namespace App\Import;
use Kip\Database;

/** The Fictioneer (WXR) orchestrator, the eFiction Importer's twin: ONE
 *  transaction wraps the whole pass (dry-run rolls it back; commit applies
 *  it), resume is import_map idempotency (plus natural-key idempotency for
 *  terms, accounts, pages, and lists), and every uncertainty becomes a
 *  Report line. A WXR has no manifest, so verification is the reader's
 *  per-type item counts against the insert tallies, printed as MISMATCH
 *  flags. The ~60-line orchestration skeleton duplicates the eFiction
 *  importer on purpose: the stable 1083-line orchestrator is not worth a
 *  risky extraction; Runs (Task 1) carries what genuinely is shared. */
final class FictioneerImport
{
    public function __construct(
        private Database $db,
        private WxrReader $reader,
        private string $mode,     // 'dry-run' | 'commit'
        private ?int $ownerId,    // reading-list owner for collections (CLI-resolved)
        private array $config = [],
    ) {}

    private Report $report;
    private int $runId = 0;
    /** @var array<string, int> */
    private array $counts = [];
    /** @var array<string, true> */
    private array $eras = [];
    /** @var array<string, int> legacy login -> users.id */
    private array $authorMap = [];
    /** @var array<int, int> wp author id -> users.id (co-author resolution) */
    private array $authorIdMap = [];
    /** @var array<string, int> 'domain|nicename' -> categories.id */
    private array $catMap = [];
    /** @var array<string, int> 'domain|nicename' -> tags.id */
    private array $tagMap = [];
    /** @var array<string, int> ladder label -> ratings.id */
    private array $ratingMap = [];
    /** @var array<int, string> wp story id -> current ladder label (chapter promotion) */
    private array $storyRating = [];
    /** @var array<string, int> legacy chapter post id -> new chapters.id */
    private array $chapterMap = [];
    /** @var array<int, list<int>> story meta fictioneer_story_chapters, wp story id -> ordered wp chapter ids */
    private array $chapterOrder = [];
    /** @var array<int, int> wp story id -> next free position for orphans/oneshots */
    private array $nextPosition = [];
    /** @var array<int, array{slug: string}> */
    private array $storySlugs = [];
    private int $httpUpgrades = 0;
    private int $oldHostRefs = 0;
    private int $oneshots = 0;
    private int $lockedItems = 0;
    /** @var array<string, true> slugs taken during this run */
    private array $localSlugs = [];

    /** import_map keys are site-scoped: two WordPress sites imported into
     *  one archive never share a post id namespace. */
    private function key(string $legacyId): string
    {
        return $this->reader->siteHost() . '/' . $legacyId;
    }

    public function run(): string
    {
        $optionsHash = sha1(json_encode(['owner' => $this->ownerId]));
        Runs::assertMigrated($this->db);
        Runs::assertOptionsCompatible($this->db, 'fictioneer', $optionsHash);
        $this->report = new Report();
        $sync = null;
        if ($this->mode === 'commit') {
            $snapshot = dirname($this->reader->path()) . '/pre-import-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
            $this->db->query('VACUUM INTO ?', [$snapshot]);
            $sync = (int) $this->db->one('PRAGMA synchronous')['synchronous'];
            $this->db->exec('PRAGMA synchronous = OFF');
        }
        $this->db->begin();
        try {
            $this->runId = Runs::newRun($this->db, 'fictioneer', $optionsHash);
            $this->passUsers();
            $this->passTaxonomy();
            $this->passStories();
            $this->passChapters();
            $this->passReviews();
            $this->passPagesAndLists();
            if ($this->mode === 'dry-run') {
                $this->db->rollBack();
                return $this->renderReport() . "dry-run: nothing written\n";
            }
            $this->db->commit();
            if ($sync !== null) $this->db->exec("PRAGMA synchronous = {$sync}");
            Runs::finishRun($this->db, $this->runId);
            (new LegacyRedirects())->writeForFictioneer($this->db);
            \App\StaticCache\Builder::build($this->config, $this->config['static_cache']['dir'] ?? dirname(__DIR__, 3) . '/public/cache');
            return $this->renderReport() . $this->verify()
                . "snapshot: see pre-import-*.sqlite next to the export; restore = copy it over the DB\n";
        } catch (\Throwable $e) {
            if ($this->db->transactionDepth() > 0) $this->db->rollBack();
            throw $e;
        }
    }

    private function passUsers(): void
    {
        $authors = $this->reader->authors(); // wp author id => login/email/display_name
        $byLogin = [];
        foreach ($authors as $wpId => $a) {
            if ($a['login'] !== '') $byLogin[$a['login']] = $a + ['wp_id' => (int) $wpId];
        }
        $seen = [];
        foreach ($this->reader->items() as $item) {
            if (!FictioneerMapper::isFictioneerType($item['type']) || $item['login'] === '') continue;
            $this->noteEras($item['meta']); // every fictioneer item, not just the first per login
            if (isset($seen[$item['login']])) continue;
            $seen[$item['login']] = true;
            $this->ensureUser($item['login'], $byLogin[$item['login']] ?? null);
        }
        // co-authors are wp user ids: give every referenced author an account too
        foreach ($this->reader->items() as $item) {
            if ($item['type'] !== FictioneerMapper::STORY) continue;
            foreach ((array) FictioneerMapper::metaList($item['meta']['fictioneer_story_co_authors'] ?? null) as $wpId) {
                $rec = $authors[(int) $wpId] ?? null;
                if ($rec === null || $rec['login'] === '') { $this->report->drop('co-author id with no author record in the WXR'); continue; }
                $this->ensureUser($rec['login'], $rec + ['wp_id' => (int) $wpId]);
            }
        }
    }

    /** Lookup-or-create by email (natural key, resume-safe); records the
     *  login in import_map and the wp id for co-author resolution. */
    private function ensureUser(string $login, ?array $author): int
    {
        if (isset($this->authorMap[$login])) {
            if (isset($author['wp_id'])) $this->authorIdMap[(int) $author['wp_id']] = $this->authorMap[$login];
            return $this->authorMap[$login];
        }
        $u = FictioneerMapper::mapUser($login, $author);
        $existing = $this->db->one('SELECT id FROM users WHERE email = ?', [$u['email']]);
        if ($existing !== null) {
            $id = (int) $existing['id'];
        } else {
            $penname = $u['penname'];
            for ($n = 2; $this->db->one('SELECT 1 AS x FROM users WHERE penname = ?', [$penname]) !== null; $n++) {
                $penname = $u['penname'] . " ($n)";
            }
            $this->db->query('INSERT INTO users (email, password_hash, penname, role) VALUES (?, ?, ?, ?)',
                [$u['email'], $u['password_hash'], $penname, $u['role']]);
            $id = (int) $this->db->lastInsertId();
            $this->record('fcn_user', $this->key($login), 'users', $id);
            $this->counts['users (from the author block)'] = ($this->counts['users (from the author block)'] ?? 0) + 1;
            if ($u['synthetic_email']) $this->report->drop('author without a usable email in the WXR (synthetic address, fix it)');
        }
        $this->authorMap[$login] = $id;
        if (isset($author['wp_id'])) $this->authorIdMap[(int) $author['wp_id']] = $id;
        return $id;
    }

    private function passTaxonomy(): void
    {
        foreach ($this->reader->items() as $item) {
            if (!FictioneerMapper::isFictioneerType($item['type'])) continue;
            // categories: fandoms and WP categories
            foreach ($item['terms'] as $t) {
                if ($t['domain'] !== 'fcn_fandom' && $t['domain'] !== 'category') continue;
                $key = $t['domain'] . '|' . $t['nicename'];
                if (isset($this->catMap[$key])) continue;
                // resume: the slug is the category's natural key (the tags
                // idiom below): an existing row adopts the term and fills
                // the map from the database, so a re-run neither re-inserts
                // under a suffixed slug nor orphans a later story's junction
                $base = FictioneerMapper::slugify($t['name'] !== '' ? $t['name'] : $t['nicename']);
                if (($existing = $this->db->one('SELECT id FROM categories WHERE slug = ?', [$base])) !== null) {
                    $this->catMap[$key] = (int) $existing['id'];
                    continue;
                }
                $slug = \App\Slug::unique(
                    fn(string $s): bool => $this->db->one('SELECT 1 AS x FROM categories WHERE slug = ?', [$s]) !== null || isset($this->localSlugs["c:$s"]),
                    $base
                );
                $this->localSlugs["c:$slug"] = true;
                $this->db->query('INSERT INTO categories (name, slug) VALUES (?, ?)', [$t['name'] !== '' ? $t['name'] : $t['nicename'], $slug]);
                $this->catMap[$key] = (int) $this->db->lastInsertId();
                $this->counts['categories (from fandom terms)'] = ($this->counts['categories (from fandom terms)'] ?? 0) + 1;
            }
            // tags: genre / content types by domain
            foreach ($item['terms'] as $t) {
                $type = FictioneerMapper::tagTypeFor($t['domain']);
                if ($type === null) continue;
                $key = $t['domain'] . '|' . $t['nicename'];
                if (isset($this->tagMap[$key])) continue;
                $typeRow = $this->db->one('SELECT id FROM tag_types WHERE name = ?', [$type]);
                if ($typeRow === null) throw new \RuntimeException("tag type '{$type}' missing: run the seeder first");
                $typeId = (int) $typeRow['id'];
                $existing = $this->db->one('SELECT id FROM tags WHERE tag_type_id = ? AND name = ?', [$typeId, $t['name']]);
                if ($existing !== null) {
                    $this->tagMap[$key] = (int) $existing['id'];
                    continue;
                }
                $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$typeId, $t['name']]);
                $this->tagMap[$key] = (int) $this->db->lastInsertId();
            }
        }
        $this->counts['tags (from genre/character/warning/tag terms)'] = count($this->tagMap);
        // the ratings ladder is seeded; unknown labels resolve to one Unrated row
        $this->ratingMap = [];
        foreach (['General', 'Teen', 'Mature', 'Explicit'] as $label) {
            $row = $this->db->one('SELECT id FROM ratings WHERE label = ?', [$label]);
            if ($row !== null) $this->ratingMap[$label] = (int) $row['id'];
        }
        if ($this->ratingMap === []) throw new \RuntimeException('no seeded ratings found: run the seeder first');
    }

    private function passStories(): void
    {
        foreach ($this->reader->items() as $item) {
            if ($item['type'] !== FictioneerMapper::STORY) continue;
            $vis = FictioneerMapper::visibility($item['status'], $item['meta']);
            if ($vis === 'private') { $this->report->reject("story not public on the source ({$item['status']})"); continue; }
            if ($vis === 'scheduled') {
                // stories have no publish_at and releaseDue() releases chapters
                // only: a scheduled story lands unvalidated for the operator
                $vis = 'unlisted';
                $this->report->drop('scheduled story imported unvalidated (validate it when its date passes)');
            }
            $authorId = $this->authorMap[$item['login']] ?? null;
            if ($authorId === null) { $this->report->reject('story without an author login'); continue; }
            // resume: a mapped story still feeds the order map, its slug, and
            // its current rating (new chapters may arrive for it), but inserts nothing
            $order = FictioneerMapper::metaList($item['meta']['fictioneer_story_chapters'] ?? null);
            $this->chapterOrder[$item['id']] = array_values(array_filter(array_map('intval', (array) $order)));
            $this->nextPosition[$item['id']] = count($this->chapterOrder[$item['id']]) + 1;
            if (($mappedStory = $this->mappedId(FictioneerMapper::STORY, $this->key((string) $item['id']))) !== null) {
                $row = $this->db->one('SELECT s.slug, r.label FROM stories s JOIN ratings r ON r.id = s.rating_id WHERE s.id = ?', [$mappedStory]);
                $this->storySlugs[$item['id']] = ['slug' => (string) ($row['slug'] ?? '')];
                $this->storyRating[$item['id']] = (string) ($row['label'] ?? 'General');
                $this->report->alreadyMapped++;
                continue;
            }
            $slug = \App\Slug::unique(
                fn(string $s): bool => $this->db->one('SELECT 1 AS x FROM stories WHERE slug = ?', [$s]) !== null || isset($this->localSlugs["s:$s"]),
                $item['slug'] !== '' ? FictioneerMapper::slugify($item['slug']) : FictioneerMapper::slugify($item['title'])
            );
            $this->localSlugs["s:$slug"] = true;
            $rating = $this->resolveRating($item['meta']['fictioneer_story_rating'] ?? null);
            $completed = FictioneerMapper::completed($item['meta']['fictioneer_story_status'] ?? null);
            if ($completed === null && ($item['meta']['fictioneer_story_status'] ?? '') !== '') {
                $this->report->sample('story status "' . $item['meta']['fictioneer_story_status'] . '" has no equivalent; imported ongoing');
            }
            $created = FictioneerMapper::iso($item['date']) ?? date('c');
            // WordPress exports post_password in plaintext: the original
            // password survives (hashed), and readers who knew it keep
            // access. An expired password (fictioneer_post_password_expiration_date
            // in the past) means the chapter was public on the source: import
            // it open, never more gated than it lived either.
            $expires = (string) ($item['meta']['fictioneer_post_password_expiration_date'] ?? '');
            $passwordExpired = $item['password'] !== '' && $expires !== ''
                && strtotime($expires) !== false && strtotime($expires) < time();
            $access = $item['password'] !== '' && !$passwordExpired ? password_hash($item['password'], PASSWORD_DEFAULT) : null;
            if ($access !== null) { $this->lockedItems++; }
            if ($passwordExpired) { $this->report->drop('expired post password (imported open, as it lived)'); }
            $this->db->query(
                'INSERT INTO stories (title, slug, summary, notes, meta_description, author_id, rating_id, completed, validated, word_count, created_at, updated_at, access_hash, is_original) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0)',
                [$item['title'], $slug, FictioneerMapper::summary($item['meta'], $item['content']),
                 $this->prose((string) ($item['meta']['fictioneer_story_global_note'] ?? '')),
                 mb_substr(FictioneerMapper::metaDescription($item['meta']), 0, 300),
                 $authorId, $rating, $completed === true ? 1 : 0, $vis === 'public' ? 1 : 0,
                 $created, FictioneerMapper::iso($item['modified']) ?? $created, $access]);
            $id = (int) $this->db->lastInsertId();
            $this->record(FictioneerMapper::STORY, $this->key((string) $item['id']), 'stories', $id);
            $this->storySlugs[$item['id']] = ['slug' => $slug];
            $this->storyRating[$item['id']] = array_search($rating, $this->ratingMap, true) ?: 'Unrated';
            $this->counts['stories'] = ($this->counts['stories'] ?? 0) + 1;
            // junctions: categories then tags
            foreach ($item['terms'] as $t) {
                $key = $t['domain'] . '|' . $t['nicename'];
                if ($t['domain'] === 'fcn_fandom' || $t['domain'] === 'category') {
                    if (isset($this->catMap[$key])) $this->db->query('INSERT OR IGNORE INTO story_categories (story_id, category_id) VALUES (?, ?)', [$id, $this->catMap[$key]]);
                } elseif (isset($this->tagMap[$key])) {
                    $this->db->query('INSERT OR IGNORE INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$id, $this->tagMap[$key]]);
                }
            }
            // co-authors resolve through the channel's author block (wp id -> account)
            foreach ((array) FictioneerMapper::metaList($item['meta']['fictioneer_story_co_authors'] ?? null) as $wpId) {
                $coId = $this->authorIdMap[(int) $wpId] ?? null;
                if ($coId === null || $coId === $authorId) { if ($coId === null) $this->report->drop('co-author id without an account (not in the author block)'); continue; }
                $this->db->query('INSERT OR IGNORE INTO coauthors (story_id, user_id) VALUES (?, ?)', [$id, $coId]);
                $this->counts['co-author links'] = ($this->counts['co-author links'] ?? 0) + 1;
            }
            // counted losses
            if (FictioneerMapper::metaList($item['meta']['fictioneer_story_custom_pages'] ?? null)) {
                $this->report->drop('story custom pages');
            }
            foreach (['fictioneer_story_redirect_link', 'fictioneer_story_topwebfiction_link', 'fictioneer_story_changelog',
                      'fictioneer_patreon_link', 'fictioneer_kofi_link', 'fictioneer_subscribestar_link',
                      'fictioneer_paypal_link', 'fictioneer_donation_link', 'fictioneer_story_css',
                      'fictioneer_story_epub_preface', 'fictioneer_story_epub_afterword'] as $k) {
                if (($item['meta'][$k] ?? '') !== '') $this->report->drop("per-story field without a home: $k");
            }
        }
    }

    private function passChapters(): void
    {
        foreach ($this->reader->items() as $item) {
            if ($item['type'] !== FictioneerMapper::CHAPTER) continue;
            $vis = FictioneerMapper::visibility($item['status'], $item['meta']);
            if ($vis === 'private') { $this->report->reject("chapter not public on the source ({$item['status']})"); continue; }
            // The chapter link is dual-source since Fictioneer 5.26.0 and the
            // theme's own getter prefers post_parent over the meta
            // (fictioneer_get_chapter_story_id, _helpers-query.php:301-308);
            // WXR carries wp:post_parent natively. Parent wins, meta is the
            // fallback, disagreements are sampled (the code-audit finding:
            // a meta-only read can misplace chapters or their order).
            $parentId = (int) ($item['parent'] ?? 0);
            $metaId = (int) ($item['meta']['fictioneer_chapter_story'] ?? 0);
            $storyWpId = $parentId > 0 ? $parentId : $metaId;
            if ($parentId > 0 && $metaId > 0 && $parentId !== $metaId) {
                $this->report->sample("chapter {$item['id']}: post_parent and fictioneer_chapter_story disagree; post_parent won");
            }
            $mapped = $storyWpId > 0 ? $this->mappedId(FictioneerMapper::STORY, $this->key((string) $storyWpId)) : null;
            if ($mapped === null) { $this->report->reject('orphan chapter (story not found or not imported)'); continue; }
            // never more public than it lived: a stricter chapter rating lifts
            // the story's rating (the gate keys on the story), for mapped and
            // new chapters alike
            if (isset($this->storyRating[$storyWpId])) {
                $stricter = FictioneerMapper::stricterRating($this->storyRating[$storyWpId], $item['meta']['fictioneer_chapter_rating'] ?? null);
                if ($stricter !== $this->storyRating[$storyWpId] && isset($this->ratingMap[$stricter])) {
                    $this->db->query('UPDATE stories SET rating_id = ? WHERE id = ?', [$this->ratingMap[$stricter], $mapped]);
                    $this->storyRating[$storyWpId] = $stricter;
                    $this->report->drop('story rating lifted by a stricter chapter rating');
                }
            }
            // resume: a mapped chapter only refreshes the in-run map for reviews
            if (($mappedChapter = $this->mappedId(FictioneerMapper::CHAPTER, $this->key((string) $item['id']))) !== null) {
                $this->chapterMap[(int) $item['id']] = $mappedChapter;
                $this->report->alreadyMapped++;
                continue;
            }
            $locked = $item['password'] !== '';
            // a locked chapter keeps its prose and its ORIGINAL password (WXR
            // exports post_password in plaintext); the gate does the rest.
            // An expired password (fictioneer_post_password_expiration_date in
            // the past) means it was public on the source: import it open.
            $expires = (string) ($item['meta']['fictioneer_post_password_expiration_date'] ?? '');
            $passwordExpired = $locked && $expires !== ''
                && strtotime($expires) !== false && strtotime($expires) < time();
            $content = $this->prose($item['content']);
            if ($locked && !$passwordExpired) {
                $this->lockedItems++;
                $this->report->drop('password-protected chapter (original password kept, gated)');
            }
            if ($passwordExpired) {
                $locked = false;
                $this->report->drop('expired post password (imported open, as it lived)');
            }
            $position = $this->positionFor($storyWpId, (int) $item['id']);
            $created = FictioneerMapper::iso($item['date']) ?? date('c');
            $this->db->query(
                'INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count, created_at, updated_at, publish_at, access_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$mapped, $position, $item['title'],
                 $this->prose((string) ($item['meta']['fictioneer_chapter_foreword'] ?? '')),
                 $content,
                 $this->prose((string) ($item['meta']['fictioneer_chapter_afterword'] ?? '')),
                 ($vis === 'public') ? 1 : 0, // locked chapters stay validated: their original password gates them
                 \App\Markdown::wordCount($content),
                 $created, FictioneerMapper::iso($item['modified']) ?? $created,
                 $vis === 'scheduled' ? $created : null, // a future post's GMT date IS its scheduled instant
                 $locked ? password_hash($item['password'], PASSWORD_DEFAULT) : null]);
            $id = (int) $this->db->lastInsertId();
            $this->chapterMap[(int) $item['id']] = $id;
            $this->record(FictioneerMapper::CHAPTER, $this->key((string) $item['id']), 'chapters', $id);
            $this->counts['chapters'] = ($this->counts['chapters'] ?? 0) + 1;
            // chapter slug legacy rows: slug data exists only here, so they
            // are written inside the transaction (INSERT OR IGNORE: resume-safe)
            if ($item['slug'] !== '') {
                $this->db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, \'\', \'story\', ?)',
                    ['/chapter/' . $item['slug'], $mapped]);
                $this->db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (\'\', ?, \'story\', ?)',
                    ['fcn_chapter=' . $item['slug'], $mapped]); // the ?fcn_chapter= query fallback
                $storySlug = $this->storySlugs[$storyWpId]['slug'] ?? '';
                if ($storySlug !== '') {
                    $this->db->query('INSERT OR IGNORE INTO legacy_urls (legacy_path, params, target_type, target_id) VALUES (?, \'\', \'story\', ?)',
                        ['/story/' . $storySlug . '/' . $item['slug'], $mapped]);
                }
            }
        }
        // one-shots: the prose lives on the story post; it becomes chapter 1
        // when the story shipped no chapters at all (checked against what
        // actually landed, never the order array alone)
        foreach ($this->reader->items() as $item) {
            if ($item['type'] !== FictioneerMapper::STORY || trim($item['content']) === '') continue;
            $storyId = $this->mappedId(FictioneerMapper::STORY, $this->key((string) $item['id']));
            if ($storyId === null) continue;
            if ((int) $this->db->one('SELECT COUNT(*) c FROM chapters WHERE story_id = ?', [$storyId])['c'] > 0) continue;
            // a locked STORY keeps its one-shot prose: the story-level hash gates it
            $content = $this->prose($item['content']);
            $created = FictioneerMapper::iso($item['date']) ?? date('c');
            $this->db->query(
                'INSERT INTO chapters (story_id, position, title, content, validated, word_count, created_at, updated_at) VALUES (?, 1, ?, ?, 1, ?, ?, ?)',
                [$storyId, $item['title'], $content, \App\Markdown::wordCount($content), $created, $created]);
            $this->oneshots++;
        }
        $this->compactPositions();
        // story word counts from the chapters that landed
        $this->db->query("UPDATE stories SET word_count = COALESCE((SELECT SUM(word_count) FROM chapters WHERE story_id = stories.id), 0)
                          WHERE id IN (SELECT new_id FROM import_map WHERE legacy_table = 'fcn_story')");
    }

    /** Positions come from the story's order array; chapters the array does
     *  not name (orphans, or chapters the array listed but which did not
     *  import) are appended in arrival order. Gaps left by dropped chapters
     *  are closed afterwards by compactPositions(), so prev/next reads stay
     *  contiguous. */
    private function positionFor(int $storyWpId, int $chapterWpId): int
    {
        $idx = array_search($chapterWpId, $this->chapterOrder[$storyWpId] ?? [], true);
        if ($idx !== false) return $idx + 1;
        $pos = $this->nextPosition[$storyWpId] ?? 1;
        $this->nextPosition[$storyWpId] = $pos + 1;
        return $pos;
    }

    /** Renumber each imported story's chapters 1..n in their current order.
     *  Two statements per story plus one per chapter; the +1000000 hop
     *  keeps the UNIQUE (story_id, position) constraint satisfied mid-renumber. */
    private function compactPositions(): void
    {
        foreach ($this->db->all("SELECT new_id FROM import_map WHERE legacy_table = 'fcn_story'") as $s) {
            $storyId = (int) $s['new_id'];
            $ids = array_column($this->db->all('SELECT id FROM chapters WHERE story_id = ? ORDER BY position', [$storyId]), 'id');
            if ($ids === []) continue;
            $this->db->query('UPDATE chapters SET position = position + 1000000 WHERE story_id = ?', [$storyId]);
            foreach ($ids as $i => $id) {
                $this->db->query('UPDATE chapters SET position = ? WHERE id = ?', [$i + 1, (int) $id]);
            }
        }
    }

    private function passReviews(): void
    {
        foreach ($this->reader->items() as $item) {
            if ($item['type'] !== FictioneerMapper::CHAPTER && $item['type'] !== FictioneerMapper::STORY) continue;
            foreach ($item['comments'] as $c) {
                if ($c['approved'] !== '1') { $this->report->drop('unapproved comment (pending or spam on the source)'); continue; }
                if ($c['type'] === 'private') { $this->report->drop('private comment (never made public)'); continue; }
                if ($c['type'] === 'user_deleted' || isset($c['meta']['fictioneer_deleted_by_user'])) {
                    $this->report->drop('deleted-comment tombstone');
                    continue;
                }
                if ($this->mappedId('fcn_comment', $this->key($item['id'] . ':' . $c['id'])) !== null) { $this->report->alreadyMapped++; continue; }
                $chapterId = null;
                $storyId = $this->mappedId(FictioneerMapper::STORY, $this->key((string) $item['id']));
                if ($item['type'] === FictioneerMapper::CHAPTER) {
                    $chapterId = $this->chapterMap[(int) $item['id']] ?? null;
                    $storyId = $chapterId !== null
                        ? (int) ($this->db->one('SELECT story_id FROM chapters WHERE id = ?', [$chapterId])['story_id'] ?? 0)
                        : null;
                }
                if ($storyId === null || $storyId === 0) { $this->report->drop('comment on a story that did not import'); continue; }
                $this->db->query(
                    'INSERT INTO reviews (story_id, chapter_id, user_id, guest_name, body, rating, created_at) VALUES (?, ?, NULL, ?, ?, NULL, ?)',
                    [$storyId, $chapterId, $c['author'] !== '' ? mb_substr($c['author'], 0, 60) : 'Guest',
                     $this->plain($c['content']), FictioneerMapper::iso($c['date']) ?? date('c')]);
                $this->record('fcn_comment', $this->key($item['id'] . ':' . $c['id']), 'reviews', (int) $this->db->lastInsertId());
                $this->counts['comments (as reviews)'] = ($this->counts['comments (as reviews)'] ?? 0) + 1;
            }
        }
    }

    private function passPagesAndLists(): void
    {
        foreach ($this->reader->items() as $item) {
            if ($item['type'] === FictioneerMapper::RECOMMENDATION) {
                $vis = FictioneerMapper::visibility($item['status'], $item['meta']);
                if ($vis === 'private') { $this->report->reject('recommendation not public on the source'); continue; }
                // resume key: the legacy page name this run would write
                if ($this->db->one('SELECT 1 AS x FROM legacy_page_urls WHERE legacy_name = ?', ['recommendation/' . $item['slug']]) !== null) {
                    $this->report->alreadyMapped++;
                    continue;
                }
                $slug = \App\Slug::unique(
                    fn(string $s): bool => $this->db->one('SELECT 1 AS x FROM pages WHERE slug = ?', [$s]) !== null || isset($this->localSlugs["p:$s"]),
                    'recommendation-' . ($item['slug'] !== '' ? FictioneerMapper::slugify($item['slug']) : FictioneerMapper::slugify($item['title']))
                );
                $this->localSlugs["p:$slug"] = true;
                $body = trim($this->prose($item['content']) . "\n\n" . $this->recommendationLinks($item['meta']));
                $this->db->query('INSERT INTO pages (slug, title, body) VALUES (?, ?, ?)', [$slug, $item['title'], $body]);
                $this->counts['recommendation pages'] = ($this->counts['recommendation pages'] ?? 0) + 1;
                $this->db->query('INSERT OR IGNORE INTO legacy_page_urls (legacy_name, page_slug) VALUES (?, ?)',
                    ['recommendation/' . $item['slug'], $slug]);
            }
            if ($item['type'] === FictioneerMapper::COLLECTION) {
                $vis = FictioneerMapper::visibility($item['status'], $item['meta']);
                if ($vis === 'private') { $this->report->reject('collection not public on the source'); continue; }
                if ($this->ownerId === null) {
                    throw new \RuntimeException('collections found: pass --owner=<penname|id> so they import as public reading lists');
                }
                // resume: reading lists have integer ids, so import_map is the key
                if ($this->mappedId(FictioneerMapper::COLLECTION, $this->key((string) $item['id'])) !== null) {
                    $this->report->alreadyMapped++;
                    continue;
                }
                $base = 'collection-' . ($item['slug'] !== '' ? FictioneerMapper::slugify($item['slug']) : FictioneerMapper::slugify($item['title']));
                $slug = \App\Slug::unique(
                    fn(string $s): bool => $this->db->one('SELECT 1 AS x FROM reading_lists WHERE slug = ?', [$s]) !== null || isset($this->localSlugs["l:$s"]),
                    $base
                );
                $this->localSlugs["l:$slug"] = true;
                $this->db->query('INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, ?, ?, ?, 1)',
                    [$this->ownerId, $item['title'], $slug,
                     \App\Import\FictioneerMapper::plainDescription((string) ($item['meta']['fictioneer_collection_description'] ?? ''))]);
                $listId = (int) $this->db->lastInsertId();
                $this->record(FictioneerMapper::COLLECTION, $this->key((string) $item['id']), 'reading_lists', $listId);
                $position = 1;
                foreach ((array) FictioneerMapper::metaList($item['meta']['fictioneer_collection_items'] ?? null) as $wid) {
                    $storyId = $this->mappedId(FictioneerMapper::STORY, $this->key((string) $wid));
                    if ($storyId === null) { $this->report->drop('collection item (not an imported story)'); continue; }
                    $this->db->query('INSERT OR IGNORE INTO reading_list_items (list_id, story_id, position) VALUES (?, ?, ?)', [$listId, $storyId, $position++]);
                }
                $this->counts['collections (as public reading lists)'] = ($this->counts['collections (as public reading lists)'] ?? 0) + 1;
            }
        }
    }

    private function resolveRating(?string $label): int
    {
        $mapped = FictioneerMapper::ratingLabel($label);
        if ($mapped !== null && isset($this->ratingMap[$mapped])) return $this->ratingMap[$mapped];
        if ($label !== null && $label !== '') {
            $this->report->sample("rating \"$label\" is not in the Fictioneer set; imported Unrated");
            return $this->unratedId();
        }
        return $this->unratedId();
    }

    private function unratedId(): int
    {
        $row = $this->db->one('SELECT id FROM ratings WHERE label = ?', ['Unrated']);
        if ($row !== null) return (int) $row['id'];
        $this->db->query('INSERT INTO ratings (label, is_adult, warning_text, position) VALUES (?, 0, ?, 9)', ['Unrated', '']);
        return (int) $this->db->lastInsertId();
    }

    private function recommendationLinks(array $meta): string
    {
        $lines = [];
        $one = \App\Import\FictioneerMapper::plainDescription((string) ($meta['fictioneer_recommendation_one_sentence'] ?? ''));
        if ($one !== '') $lines[] = $one;
        $author = \App\Import\FictioneerMapper::plainDescription((string) ($meta['fictioneer_recommendation_author'] ?? ''));
        if ($author !== '') $lines[] = 'Author: ' . $author;
        $urls = [];
        foreach (array_merge(
            [(string) ($meta['fictioneer_recommendation_primary_url'] ?? '')],
            (array) FictioneerMapper::metaList($meta['fictioneer_recommendation_urls'] ?? null),
            [(string) ($meta['fictioneer_recommendation_support'] ?? '')]
        ) as $u) {
            $u = trim((string) $u);
            if (preg_match('#^https?://#i', $u) === 1) $urls[] = $u;
        }
        if ($urls !== []) {
            $lines[] = '';
            $lines[] = 'Links:';
            foreach (array_unique($urls) as $i => $u) $lines[] = '- [Link ' . ($i + 1) . '](' . $u . ')';
        }
        return implode("\n", $lines);
    }

    /** The content pipeline: WordPress HTML -> markdown, then http links
     *  upgraded to https, counted. The markdown flavor renders only https
     *  links, so an http link would otherwise render as literal text. */
    private function prose(string $html): string
    {
        $md = rtrim(HtmlToMarkdown::wordpress($html));
        $n = substr_count($md, '](http://');
        if ($n > 0) {
            $md = str_replace('](http://', '](https://', $md);
            $this->httpUpgrades += $n;
        }
        // media stays where it was (text-only import, decision 11): count
        // every link or image still pointing at the old site
        $host = $this->reader->siteHost();
        if ($host !== 'unknown-site') {
            $this->oldHostRefs += preg_match_all('#\]\(https://' . preg_quote($host, '#') . '/#i', $md);
        }
        return $md;
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function noteEras(array $meta): void
    {
        if (array_key_exists('_fictioneer_story_short_description', $meta) || array_key_exists('_fictioneer_chapter_story', $meta)) {
            $this->eras['ACF era (5.0-5.7): reference rows ignored'] = true;
        }
        foreach (FictioneerMapper::ERA_MARKERS as $key => $era) {
            if (array_key_exists($key, $meta)) $this->eras[$era] = true;
        }
        if (($meta['_word_count'] ?? '') !== '') $this->eras['cached word counts ignored (recomputed)'] = true;
    }

    private function mappedId(string $legacyTable, string $legacyId): ?int
    {
        $row = $this->db->one('SELECT new_id FROM import_map WHERE legacy_table = ? AND legacy_id = ?', [$legacyTable, $legacyId]);
        return $row === null ? null : (int) $row['new_id'];
    }

    private function record(string $legacyTable, string $legacyId, string $newTable, int $newId): void
    {
        $this->db->query('INSERT OR REPLACE INTO import_map (legacy_table, legacy_id, new_table, new_id, run_id) VALUES (?, ?, ?, ?, ?)',
            [$legacyTable, $legacyId, $newTable, $newId, $this->runId]);
    }

    private function renderReport(): string
    {
        foreach ($this->counts as $table => $n) { $this->report->table($table, $n); }
        $extra = '';
        if ($this->eras !== []) $extra .= 'source eras detected: ' . implode('; ', array_keys($this->eras)) . "\n";
        $extra .= "oneshot stories (story prose became chapter 1): {$this->oneshots}\n";
        $extra .= "http links upgraded to https: {$this->httpUpgrades}\n";
        $extra .= 'links and images still pointing at ' . $this->reader->siteHost() . ": {$this->oldHostRefs} (keep the old uploads reachable, or rehost)\n";
        $extra .= "password-protected items (original passwords kept, gated): {$this->lockedItems}\n";
        $extra .= 'accounts created from the author block (locked; send password resets): ' . ($this->counts['users (from the author block)'] ?? 0) . "\n";
        $extra .= "Treat the import log as semi-public: it names logins and emails from the author block; it carries no hashes.\n";
        return $this->report->render() . "\n" . $extra;
    }

    private function verify(): string
    {
        $lines = ["verification", "------------"];
        foreach ($this->reader->typeCounts as $type => $n) {
            $imported = $this->counts[match ($type) {
                FictioneerMapper::STORY => 'stories',
                FictioneerMapper::CHAPTER => 'chapters',
                FictioneerMapper::COLLECTION => 'collections (as public reading lists)',
                FictioneerMapper::RECOMMENDATION => 'recommendation pages',
                default => '',
            }] ?? 0;
            $flag = $imported <= $n ? '' : ' MISMATCH';
            $lines[] = sprintf('%-16s read %d, imported %d%s', $type, $n, $imported, $flag);
        }
        return implode("\n", $lines) . "\n";
    }
}
