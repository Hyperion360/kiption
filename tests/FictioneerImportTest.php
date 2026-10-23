<?php // tests/FictioneerImportTest.php
namespace App\Tests;
use App\Import\FictioneerImport;
use App\Import\WxrReader;
use App\Tests\Support\Wxr;
use Kip\Database;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Drives the REAL Fictioneer orchestrator end to end: a throwaway sqlite
 *  file, Migrator + Seeder, in-process runs, a real config array with
 *  throwaway dirs. Covers the four 5.x data-model eras: honest counts on a
 *  mixed export, original passwords kept, scheduled posts, two sites with
 *  colliding post ids, orphaned/dropped chapter positions, hostile meta,
 *  DOCTYPE refusal, and parent-link precedence. */
final class FictioneerImportTest extends TestCase
{
    private string $dir = '';
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kiption-fximp-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0777, true);
        $this->dbPath = $this->dir . '/db.sqlite';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function db(): Database
    {
        return new Database('sqlite:' . $this->dbPath);
    }

    private function readyDb(): Database
    {
        $db = $this->db();
        (new \Kip\Migrations\Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        \App\Seeder::run($db);
        return $db;
    }

    private function config(): array
    {
        return [
            'db' => ['dsn' => 'sqlite:' . $this->dbPath],
            'static_cache' => ['dir' => $this->dir . '/cache', 'enabled' => false],
            'nav_file' => '',
            'log_db' => ['dsn' => 'sqlite::memory:'],
            'mail' => ['transport' => 'log', 'log_path' => $this->dir . '/mail.log', 'from' => 'noreply@localhost'],
            'uploads' => ['dir' => $this->dir . '/uploads'],
            'app_dir' => dirname(__DIR__) . '/app',
            'views' => dirname(__DIR__) . '/app/views',
            'env' => 'prod',
            'site_name' => 'Kiption', 'base_url' => 'https://archive.example',
        ];
    }

    private function write(string $xml): string
    {
        $p = $this->dir . '/export.xml';
        file_put_contents($p, $xml);
        return $p;
    }

    private function import(string $path, string $mode = 'commit', ?int $owner = null): string
    {
        return (new FictioneerImport($this->db(), new WxrReader($path), $mode, $owner, $this->config()))->run();
    }

    public function test_oneshot_story_prose_becomes_chapter_one(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'one-shot', 'title' => 'One Shot',
                'content' => '<p>The whole tale.</p>',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'one-shot')")['c']);
        $this->assertSame('1', (string) $db->one("SELECT completed FROM stories WHERE slug = 'one-shot'")['completed']);
        $this->assertStringContainsString('oneshot stories (story prose became chapter 1): 1', $out);
    }

    /** A story without a short description falls back to its prose as the
     *  summary, capped at the story form's 1000 characters (the form's
     *  maxlength, the Epub cap precedent): readers never meet the whole
     *  story duplicated above its chapters. */
    public function test_summary_fallback_caps_at_the_forms_1000_characters(): void
    {
        $db = $this->readyDb();
        $prose = trim(str_repeat('word ', 240)); // 1199 characters, plain single-spaced text
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'no-blurb', 'title' => 'No Blurb', 'content' => $prose,
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]),
        ]);
        $p = $this->write($xml);
        $this->import($p, 'commit');
        $summary = (string) $db->one("SELECT summary FROM stories WHERE slug = 'no-blurb'")['summary'];
        $this->assertSame(1000, mb_strlen($summary), 'the fallback never runs past the form contract');
        $this->assertSame(mb_substr($prose, 0, 1000), $summary, 'and it is the prose itself, from the top');
    }

    /** The category natural key is its slug (the tags idiom): a re-run of the
     *  same fandom term adopts the existing row instead of re-inserting under
     *  a suffixed slug, and the database-fed catMap lets a NEW story in a
     *  later run link that same adopted category. */
    public function test_taxonomy_is_idempotent_on_resume(): void
    {
        $db = $this->readyDb();
        $term = ['domain' => 'fcn_fandom', 'nicename' => 'fantasy', 'name' => 'Fantasy'];
        $story = fn(int $id, string $slug): string => Wxr::item(['id' => $id, 'type' => 'fcn_story',
            'slug' => $slug, 'title' => 'Story ' . $id, 'content' => '',
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                       'fictioneer_story_chapters' => serialize([])],
            'terms' => [$term]]);
        $a = $this->dir . '/a.xml';
        $b = $this->dir . '/b.xml';
        file_put_contents($a, Wxr::channel([$story(10, 'from-a')]));
        file_put_contents($b, Wxr::channel([$story(20, 'from-b')]));
        $cats = fn(): string => (string) $db->one('SELECT COUNT(*) c FROM categories')['c'];
        $junctions = fn(): string => (string) $db->one("SELECT COUNT(*) c FROM story_categories sc
            JOIN stories s ON s.id = sc.story_id WHERE s.slug IN ('from-a', 'from-b')")['c'];
        $baseline = $cats(); // the seeder's starter category

        $this->import($a, 'commit');
        $afterFirst = $cats();
        $this->assertSame((string) ($baseline + 1), $afterFirst, 'exactly one category for the fandom term');
        $this->assertSame('fantasy', (string) $db->one("SELECT slug FROM categories WHERE name = 'Fantasy'")['slug']);
        $this->assertSame('1', $junctions());

        // the same WXR again: the mapped story skips its junctions, the term
        // adopts the existing category, nothing re-inserts
        $this->import($a, 'commit');
        $this->assertSame($afterFirst, $cats(), 'the fandom term must not re-insert');
        $this->assertSame('fantasy', (string) $db->one("SELECT slug FROM categories WHERE name = 'Fantasy'")['slug'], 'no suffixed -2 slug');
        $this->assertSame('1', $junctions());

        // a NEW story in a later run with the same term links the adopted row
        $this->import($b, 'commit');
        $this->assertSame($afterFirst, $cats());
        $rows = $db->all("SELECT c.slug FROM story_categories sc JOIN categories c ON c.id = sc.category_id
                          JOIN stories s ON s.id = sc.story_id WHERE s.slug IN ('from-a', 'from-b')");
        $this->assertSame([['slug' => 'fantasy'], ['slug' => 'fantasy']], $rows, 'both stories point at the one adopted category');
    }

    /** Split/per-era exports omit the story post from a later file
     *  (WordPress date-range cuts): its chapters must append after the
     *  ones that already landed, seeded from the database, not die on the
     *  UNIQUE (story_id, position) constraint the WXR-alone seeding hit. */
    public function test_split_file_without_the_story_post_appends_after_landed_chapters(): void
    {
        $db = $this->readyDb();
        $first = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'serial', 'title' => 'Serial', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([20])]]),
            Wxr::chapter(20, 10, ['title' => 'First']),
        ]);
        $second = Wxr::channel([ // the story post is not in this file
            Wxr::chapter(21, 10, ['title' => 'Second']),
            Wxr::chapter(22, 10, ['title' => 'Third']),
        ]);
        $a = $this->dir . '/first.xml';
        $b = $this->dir . '/second.xml';
        file_put_contents($a, $first);
        file_put_contents($b, $second);
        $this->import($a, 'commit');
        $this->import($b, 'commit'); // before the fix: chapter 21 collided with First at position 1 and killed the transaction
        $rows = $db->all("SELECT position, title FROM chapters
                          WHERE story_id = (SELECT id FROM stories WHERE slug = 'serial') ORDER BY position");
        $this->assertSame([[1, 'First'], [2, 'Second'], [3, 'Third']],
            array_map(static fn(array $r): array => [(int) $r['position'], $r['title']], $rows));
    }

    /** A one-shot's prose landed as chapter 1; a later file splits the
     *  story into real chapters (the story post returns with a chapter
     *  list). They append after the one-shot instead of colliding with it
     *  at position 1, and the one-shot itself is not duplicated. */
    public function test_real_chapters_after_a_oneshot_append_instead_of_colliding(): void
    {
        $db = $this->readyDb();
        $story = fn(array $over): string => Wxr::item(array_merge([
            'id' => 10, 'type' => 'fcn_story', 'slug' => 'split-tale', 'title' => 'Split Tale',
            'content' => '<p>The whole tale, once.</p>',
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot',
                       'fictioneer_story_chapters' => serialize([])],
        ], $over));
        $first = Wxr::channel([$story([])]);
        $second = Wxr::channel([
            $story(['meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                               'fictioneer_story_chapters' => serialize([21, 22])]]),
            Wxr::chapter(21, 10, ['title' => 'Part One']),
            Wxr::chapter(22, 10, ['title' => 'Part Two']),
        ]);
        $a = $this->dir . '/first.xml';
        $b = $this->dir . '/second.xml';
        file_put_contents($a, $first);
        file_put_contents($b, $second);
        $this->import($a, 'commit');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'split-tale')")['c'], 'the one-shot landed as chapter 1');
        $this->import($b, 'commit'); // before the fix: Part One hit the one-shot at position 1 and killed the transaction
        $rows = $db->all("SELECT position, title FROM chapters
                          WHERE story_id = (SELECT id FROM stories WHERE slug = 'split-tale') ORDER BY position");
        $this->assertSame([[1, 'Split Tale'], [2, 'Part One'], [3, 'Part Two']],
            array_map(static fn(array $r): array => [(int) $r['position'], $r['title']], $rows), 'appended after the one-shot, no duplicate one-shot');
    }

    /** The whole-era matrix in one export: ACF-era meta (5.0-5.7), the
     *  pre-5.10 and 5.10+ SEO spellings, fcn_hidden (5.35), a draft, a
     *  recommendation, a collection, notes, comments in every state, and a
     *  http link. Counts must be honest, the owner gate must roll the run
     *  back, and a resume run must skip exactly what it already mapped. */
    public function test_all_eras_import_with_honest_counts(): void
    {
        $db = $this->readyDb();
        $storyId = 11; $ch1 = 100; $ch2 = 101;
        $xml = Wxr::channel([
            Wxr::acfStory($storyId, ['login' => 'anna']),
            Wxr::chapter($ch1, $storyId, ['title' => 'First', 'content' => '<h2>First</h2><p>Alpha text.</p>', 'login' => 'anna',
                'meta' => ['fictioneer_chapter_story' => (string) $storyId,
                            'fictioneer_chapter_foreword' => '<p>Before note.</p>',
                            'fictioneer_chapter_afterword' => '<p>After note.</p>'],
                'comments' => [
                    ['author' => 'Go', 'content' => '<p>Loved it.</p>', 'approved' => '1'],
                    ['author' => 'Shy', 'content' => '<p>Secret.</p>', 'type' => 'private', 'approved' => '1'],
                    ['author' => 'Ghost', 'content' => '<p>Bye.</p>', 'meta' => ['fictioneer_deleted_by_user' => '1'], 'approved' => '1'],
                    ['author' => 'Bot', 'content' => '<p>Spam.</p>', 'approved' => '0'],
                ]]),
            Wxr::chapter($ch2, $storyId, ['title' => 'Second', 'content' => '<p>Beta text.</p><p><a href="http://old.example.org/about">about</a> <img src="https://old.example.org/u/a.jpg" alt="pic"></p>', 'login' => 'anna',
                'meta' => ['fictioneer_chapter_story' => (string) $storyId, 'fictioneer_chapter_rating' => 'Mature']]),
            Wxr::hiddenStory(200),
            Wxr::item(['id' => 300, 'type' => 'fcn_recommendation', 'slug' => 'great-book', 'title' => 'A Great Book',
                'login' => 'anna', 'meta' => [
                    'fictioneer_recommendation_one_sentence' => 'Read this one.',
                    'fictioneer_recommendation_author' => 'Someone',
                    'fictioneer_recommendation_primary_url' => 'https://books.example/great',
                    'fictioneer_recommendation_urls' => serialize(['http://books.example/great', 'http://mirror.example/read']),
                    'fictioneer_recommendation_support' => 'ftp://nope',
                ]]),
            Wxr::item(['id' => 301, 'type' => 'fcn_story', 'status' => 'draft', 'slug' => 'draft-story', 'title' => 'Draft']),
            Wxr::item(['id' => 302, 'type' => 'fcn_collection', 'slug' => 'best-of', 'title' => 'Best of',
                'login' => 'anna', 'status' => 'publish',
                'meta' => [
                    'fictioneer_collection_items' => serialize([$storyId, 9999]),
                    'fictioneer_collection_description' => 'Hand picked.',
                ]]),
            // the wp chapter ids in the story's order array map via their own post ids
        ], [
            ['id' => 3, 'login' => 'anna', 'email' => 'anna@example.org', 'display_name' => 'Anna S.'],
            ['id' => 4, 'login' => 'beta', 'email' => 'beta.wp@example.org'],
        ]);
        // the story's order array must name real chapter wp ids: rewrite acfStory for the fixture
        $xml = str_replace(serialize([100 + $storyId]), serialize([$ch1, $ch2]), $xml);
        $p = $this->write($xml);

        // collections exist: the run stops with the owner instruction and rolls back
        try {
            $this->import($p, 'commit');
            $this->fail('expected the owner requirement');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pass --owner=', $e->getMessage());
        }
        $this->assertSame('0', (string) $db->one("SELECT COUNT(*) c FROM stories WHERE slug IN ('acf-story-11', 'hidden-story-200', 'draft-story')")['c'], 'the failed run left nothing behind');

        $ownerId = (int) $db->one("SELECT id FROM users WHERE penname = 'Demo Author'")['id'];
        $out = $this->import($p, 'dry-run', $ownerId);
        $this->assertStringContainsString('dry-run: nothing written', $out);
        $this->assertSame('0', (string) $db->one('SELECT COUNT(*) c FROM import_map')['c']);

        $out = $this->import($p, 'commit', $ownerId);
        // stories: acf + hidden imported, draft dropped (the seeder's demo
        // stories also live here, so imported rows are counted by slug)
        $this->assertSame('2', (string) $db->one("SELECT COUNT(*) c FROM stories WHERE slug IN ('acf-story-11', 'hidden-story-200')")['c']);
        $this->assertSame('0', (string) $db->one("SELECT COUNT(*) c FROM stories WHERE slug = 'draft-story'")['c']);
        $hidden = $db->one("SELECT s.validated, r.label FROM stories s JOIN ratings r ON r.id = s.rating_id WHERE s.slug = 'hidden-story-200'");
        $this->assertSame('0', (string) $hidden['validated'], 'fcn_hidden imports unvalidated');
        $this->assertSame('Mature', $hidden['label']);
        // era handling
        $this->assertStringContainsString('source eras detected', $out);
        $this->assertStringContainsString('ACF era', $out);
        $this->assertStringContainsString('pre-5.10 SEO fields', $out);
        $this->assertStringContainsString('5.10+ SEO fields', $out);
        $this->assertSame('Old SEO description', $db->one("SELECT meta_description FROM stories WHERE slug = 'acf-story-11'")['meta_description']);
        $this->assertSame('New era SEO', $db->one("SELECT meta_description FROM stories WHERE slug = 'hidden-story-200'")['meta_description']);
        // chapters: order from the array, notes mapped, rating promotion
        $chapters = $db->all("SELECT position, title, validated, notes_before, notes_after, content FROM chapters
                              WHERE story_id = (SELECT id FROM stories WHERE slug = 'acf-story-11') ORDER BY position");
        $this->assertCount(2, $chapters);
        $this->assertSame('First', $chapters[0]['title']);
        $this->assertSame('Before note.', trim($chapters[0]['notes_before']));
        $this->assertSame('After note.', trim($chapters[0]['notes_after']));
        $this->assertSame('**First**' . "\n\n" . 'Alpha text.', rtrim($chapters[0]['content'], "\n"));
        $acf = $db->one("SELECT r.label FROM stories s JOIN ratings r ON r.id = s.rating_id WHERE s.slug = 'acf-story-11'");
        $this->assertSame('Mature', $acf['label'], 'the Mature chapter rating lifts the Teen story');
        // word counts recomputed from the chapters that landed, never trusted
        // (the fixture's _word_count cache says 999)
        $expected = \App\Markdown::wordCount($chapters[0]['content']) + \App\Markdown::wordCount($chapters[1]['content']);
        $this->assertSame((string) $expected, (string) $db->one("SELECT word_count FROM stories WHERE slug = 'acf-story-11'")['word_count']);
        // links: http upgraded, old-host references counted, never fetched
        $this->assertStringContainsString('](https://old.example.org/about)', $chapters[1]['content']);
        $this->assertStringContainsString('http links upgraded to https: 1', $out);
        $this->assertStringContainsString('links and images still pointing at old.example.org: 2', $out);
        // comments: approved+public only (the seeder plants no reviews)
        $this->assertSame('1', (string) $db->one('SELECT COUNT(*) c FROM reviews')['c']);
        $this->assertSame('Go', $db->one('SELECT guest_name FROM reviews')['guest_name']);
        $this->assertNull($db->one('SELECT rating FROM reviews')['rating']);
        // recommendation page + legacy page name, support link scheme-filtered
        $this->assertSame('A Great Book', $db->one("SELECT title FROM pages WHERE slug LIKE 'recommendation-great-book%'")['title']);
        $this->assertNotNull($db->one("SELECT 1 FROM legacy_page_urls WHERE legacy_name = 'recommendation/great-book'"));
        $rec = (string) $db->one("SELECT body FROM pages WHERE slug LIKE 'recommendation-great-book%'")['body'];
        $this->assertStringNotContainsString('ftp://nope', $rec);
        // the flavor renders https links only: http sources are upgraded on
        // the way in, and the dedupe reindexes (Link 1, Link 2, no gaps)
        $this->assertStringContainsString('](https://books.example/great)', $rec);
        $this->assertStringContainsString('](https://mirror.example/read)', $rec);
        $this->assertStringNotContainsString('](http://', $rec);
        $this->assertStringContainsString("- [Link 1](https://books.example/great)\n- [Link 2](https://mirror.example/read)", $rec);
        // collection as a public reading list with one resolved item
        $list = $db->one("SELECT l.is_public, COUNT(x.id) items FROM reading_lists l JOIN reading_list_items x ON x.list_id = l.id WHERE l.slug = 'collection-best-of'");
        $this->assertSame('1', (string) $list['is_public']);
        $this->assertSame('1', (string) $list['items'], 'the 9999 item drops with a count');
        // accounts: real emails and display names from the author block, locked, role validated_author
        $anna = $db->one("SELECT email, penname, role FROM users WHERE email = 'anna@example.org'");
        $this->assertSame('Anna S.', $anna['penname']);
        $this->assertSame('validated_author', $anna['role']);
        // co-authors resolve through the author block (wp id 4 = beta)
        $co = $db->one("SELECT u.email FROM coauthors c JOIN users u ON u.id = c.user_id JOIN stories s ON s.id = c.story_id WHERE s.slug = 'acf-story-11'");
        $this->assertSame('beta.wp@example.org', $co['email']);
        // report lines
        $this->assertStringContainsString('dropped private comment', $out);
        $this->assertStringContainsString('dropped deleted-comment tombstone', $out);
        $this->assertStringContainsString('dropped unapproved comment', $out);
        $this->assertStringContainsString('REJECT story not public on the source (draft)', $out);
        $this->assertStringContainsString('dropped collection item', $out);
        $this->assertStringContainsString('dropped hidden on the source (imported unvalidated; validate when reviewed): 1', $out);
        // the footer's privacy claim must be true: no report line prints a
        // login or an email (the fixture's real WXR emails never appear)
        $this->assertStringContainsString('it counts accounts and drops, it carries no emails, logins, or hashes', $out);
        $this->assertStringNotContainsString('anna@example.org', $out);
        $this->assertStringNotContainsString('beta.wp@example.org', $out);
        // the verification block must be honest, not merely present: the
        // fcn_story line carries the exact counts (3 read: acf, hidden, and
        // the rejected draft; 2 imported) and names the gap as short-by-1
        // (the draft is a counted reject), equal lines carry no flag, and
        // an honest run prints no MISMATCH anywhere in the block
        $this->assertMatchesRegularExpression('/^fcn_story\s+read 3, imported 2 \(short by 1\)$/m', $out);
        $this->assertMatchesRegularExpression('/^fcn_chapter\s+read 2, imported 2$/m', $out);
        $this->assertMatchesRegularExpression('/^fcn_recommendation\s+read 1, imported 1$/m', $out);
        $this->assertMatchesRegularExpression('/^fcn_collection\s+read 1, imported 1$/m', $out);
        $this->assertStringNotContainsString('MISMATCH', $out);
        // legacy rows
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '/story/acf-story-11'")['c']);
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '' AND params = 'fcn_story=acf-story-11'")['c'], 'the ?fcn_story= query fallback got its row');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '/chapter/chapter-100'")['c']);
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '' AND params = 'p=11&post_type=fcn_story'")['c']);
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '/author/anna'")['c']);
        // resume: a second commit skips everything it already mapped
        // (2 stories + 2 chapters + 1 comment + 1 recommendation + 1 collection)
        $out2 = $this->import($p, 'commit', $ownerId);
        $this->assertStringContainsString('already mapped (skipped): 7', $out2);
        $this->assertSame('2', (string) $db->one("SELECT COUNT(*) c FROM stories WHERE slug IN ('acf-story-11', 'hidden-story-200')")['c']);
        $this->assertSame('2', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id IN (SELECT id FROM stories WHERE slug IN ('acf-story-11', 'hidden-story-200'))")['c']);
        $this->assertSame('1', (string) $db->one('SELECT COUNT(*) c FROM reviews')['c']);
        $this->assertSame('1', (string) $db->one('SELECT COUNT(*) c FROM reading_lists')['c']);
    }

    /** A WXR author whose email already belongs to a member here adopts that
     *  account: the story lands under it, no duplicate user is created, the
     *  /author/{login} 301 row still exists (the fcn_user map row rides the
     *  adoption now), and the report names the adoption without printing the
     *  login. */
    public function test_an_author_email_already_on_this_site_adopts_the_account(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'adopted-tale', 'title' => 'Adopted Tale', 'content' => '',
                'login' => 'wanderer',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]),
        ], [
            ['id' => 9, 'login' => 'wanderer', 'email' => 'demo@example.test', 'display_name' => 'Wanderer'],
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $demoId = (string) $db->one("SELECT id FROM users WHERE email = 'demo@example.test'")['id'];
        $this->assertSame('2', (string) $db->one('SELECT COUNT(*) c FROM users')['c'], 'no duplicate account for a known email');
        $this->assertSame($demoId, (string) $db->one("SELECT author_id FROM stories WHERE slug = 'adopted-tale'")['author_id'], 'the story lands under the adopted account');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM legacy_urls WHERE legacy_path = '/author/wanderer'")['c'], 'the adopted login keeps its 301 row');
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM import_map WHERE legacy_table = 'fcn_user' AND legacy_id = 'old.example.org/wanderer'")['c']);
        $this->assertStringContainsString('dropped author email already a member here (account adopted): 1', $out);
        $this->assertStringNotContainsString('wanderer', $out, 'the report carries no logins');
    }

    /** Two authors whose logins differ only by punctuation (jane-doe,
     *  jane.doe) slugify to the same synthetic address: they are two
     *  people, so the second lands as her own account with a
     *  disambiguated address instead of being merged onto the first by
     *  the email lookup. */
    public function test_punctuational_login_variants_stay_two_accounts(): void
    {
        $db = $this->readyDb();
        $story = fn(int $id, string $slug, string $login): string => Wxr::item([
            'id' => $id, 'type' => 'fcn_story', 'slug' => $slug, 'title' => 'Story ' . $id, 'content' => '',
            'login' => $login,
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]);
        $p = $this->write(Wxr::channel([$story(10, 'by-hyphen', 'jane-doe'), $story(11, 'by-dot', 'jane.doe')]));
        $this->import($p, 'commit');
        $this->assertSame('4', (string) $db->one('SELECT COUNT(*) c FROM users')['c'], 'the two seeded accounts plus one for each author');
        $hyphen = (string) $db->one("SELECT author_id FROM stories WHERE slug = 'by-hyphen'")['author_id'];
        $dot = (string) $db->one("SELECT author_id FROM stories WHERE slug = 'by-dot'")['author_id'];
        $this->assertNotSame($hyphen, $dot, 'two authors never merge onto one account');
        $this->assertSame('jane_doe@wxr-import.invalid', (string) $db->one('SELECT email FROM users WHERE id = ?', [$hyphen])['email']);
        $this->assertSame('jane_doe2@wxr-import.invalid', (string) $db->one('SELECT email FROM users WHERE id = ?', [$dot])['email']);
    }

    /** A failed commit run restores the durability pragma: synchronous goes
     *  to OFF for the bulk load, and a mid-pass throw (the owner gate here)
     *  must not leave the connection quietly non-durable after the rollback. */
    public function test_a_failed_commit_run_restores_synchronous(): void
    {
        $db = $this->readyDb();
        $before = (string) $db->one('PRAGMA synchronous')['synchronous'];
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'plain', 'title' => 'Plain', 'content' => '',
                'login' => 'anna',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']]),
            Wxr::item(['id' => 30, 'type' => 'fcn_collection', 'slug' => 'crowd', 'title' => 'Crowd', 'login' => 'anna']),
        ]);
        $p = $this->write($xml);
        try {
            (new FictioneerImport($db, new WxrReader($p), 'commit', null, $this->config()))->run();
            $this->fail('the owner requirement must stop the run');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pass --owner=', $e->getMessage());
        }
        $this->assertSame($before, (string) $db->one('PRAGMA synchronous')['synchronous'], 'the failed run restores the durability pragma');
        $this->assertSame('0', (string) $db->one('SELECT COUNT(*) c FROM import_map')['c'], 'the rolled-back run left nothing behind');
        $this->assertNotEmpty(glob($this->dir . '/pre-import-*.sqlite'), 'the snapshot was taken before the failure');
    }

    /** WordPress exports post_password in plaintext: readers who knew the
     *  password keep their access, so the ORIGINAL value is hashed in, the
     *  prose is kept (gated, never lost), and locked chapters stay validated
     *  instead of landing in draft limbo. */
    public function test_password_protected_items_keep_their_original_password(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'locked-story', 'title' => 'Locked',
                'password' => 'opensesame', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([20])]]),
            Wxr::item(['id' => 20, 'type' => 'fcn_chapter', 'slug' => 'locked-chapter', 'title' => 'LC',
                'password' => 'chapter-pw', 'content' => '<p>Secret prose.</p>',
                'meta' => ['fictioneer_chapter_story' => '10']]),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $story = $db->one("SELECT access_hash, validated FROM stories WHERE slug = 'locked-story'");
        $this->assertTrue(password_verify('opensesame', (string) $story['access_hash']), 'WordPress exports post_password in plaintext: readers keep their access');
        $this->assertSame('1', (string) $story['validated']);
        $chapter = $db->one("SELECT access_hash, content, validated FROM chapters WHERE title = 'LC'");
        $this->assertTrue(password_verify('chapter-pw', (string) $chapter['access_hash']));
        $this->assertStringContainsString('Secret prose.', $chapter['content'], 'locked prose is kept, gated, never lost');
        $this->assertSame('1', (string) $chapter['validated'], 'the original password gates it; no draft limbo');
        $this->assertStringContainsString('password-protected items (original passwords kept, gated): 2', $out);
    }

    /** Dual-source chapter links since Fictioneer 5.26.0: post_parent wins
     *  over the fictioneer_chapter_story meta (the theme's own getter does
     *  the same), the disagreement is sampled, and nothing is orphaned. */
    public function test_post_parent_link_wins_and_disagreements_are_sampled(): void
    {
        $db = $this->readyDb();
        // chapter 20 carries post_parent 10 AND meta fictioneer_chapter_story 99:
        // post_parent wins, the disagreement is sampled, nothing is orphaned
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'parent-story', 'title' => 'Parent', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([20])]]),
            Wxr::item(['id' => 20, 'type' => 'fcn_chapter', 'slug' => 'linked-twice', 'title' => 'LT',
                'parent' => 10, 'meta' => ['fictioneer_chapter_story' => '99']]),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $chapter = $db->one("SELECT story_id FROM chapters WHERE title = 'LT'");
        $this->assertNotNull($chapter, 'the meta-only read would have orphaned this chapter');
        $this->assertSame((string) $db->one("SELECT id FROM stories WHERE slug = 'parent-story'")['id'], (string) $chapter['story_id'], 'linked to the post_parent story, not the meta one');
        $this->assertStringContainsString('post_parent and fictioneer_chapter_story disagree', $out);
    }

    /** An expired post password (fictioneer_post_password_expiration_date in
     *  the past) means the post was public on the source at the end: it
     *  imports open, never more gated than it lived. */
    public function test_expired_post_password_imports_open(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'expired-gate', 'title' => 'Expired Gate',
                'password' => 'stale', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([]),
                           'fictioneer_post_password_expiration_date' => '2020-01-01 00:00:00']]),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $story = $db->one("SELECT access_hash, validated FROM stories WHERE slug = 'expired-gate'");
        $this->assertNull($story['access_hash'], 'expired on the source means public here');
        $this->assertSame('1', (string) $story['validated']);
        $this->assertStringContainsString('expired post password (imported open, as it lived)', $out);
    }

    /** A scheduled story has no publish_at of its own to land on, so it
     *  imports unvalidated for the operator; its chapters still import. */
    public function test_scheduled_story_lands_unvalidated(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'status' => 'future', 'slug' => 'later', 'title' => 'Later', 'content' => '',
                'date' => '2027-06-01 00:00:00',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing', 'fictioneer_story_chapters' => serialize([20])]]),
            Wxr::chapter(20, 10, ['title' => 'Ch']),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $this->assertSame('0', (string) $db->one("SELECT validated FROM stories WHERE slug = 'later'")['validated']);
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'later')")['c'], 'its chapters still import');
        $this->assertStringContainsString('scheduled story imported unvalidated', $out);
    }

    /** A scheduled chapter keeps its future GMT date as its publish_at and
     *  lands unvalidated: it goes live when the site's release pass says so,
     *  not at import time. */
    public function test_scheduled_chapters_arrive_scheduled(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'serial', 'title' => 'Serial', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([20])]]),
            Wxr::item(['id' => 20, 'type' => 'fcn_chapter', 'slug' => 'future-chapter', 'title' => 'Future', 'status' => 'future',
                'date' => '2027-01-01 00:00:00',
                'meta' => ['fictioneer_chapter_story' => '10']]),
        ]);
        $p = $this->write($xml);
        $this->import($p, 'commit');
        $chapter = $db->one("SELECT validated, publish_at FROM chapters WHERE title = 'Future'");
        $this->assertSame('0', (string) $chapter['validated']);
        $this->assertSame('2027-01-01T00:00:00Z', $chapter['publish_at']);
    }

    /** import_map keys are site-scoped ('{host}/{id}'): the same wp post id
     *  on two WordPress sites is two stories, and the ?p= fallback row keeps
     *  the bare id so both resolve. */
    public function test_two_sites_with_the_same_post_ids_never_collide(): void
    {
        $db = $this->readyDb();
        $a = $this->dir . '/a.xml';
        $b = $this->dir . '/b.xml';
        file_put_contents($a, Wxr::channel([Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'from-a', 'title' => 'From A', 'content' => '<p>A.</p>',
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']])], [], 'https://a.example.org/'));
        file_put_contents($b, Wxr::channel([Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'from-b', 'title' => 'From B', 'content' => '<p>B.</p>',
            'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Oneshot']])], [], 'https://b.example.org/'));
        $this->import($a, 'commit');
        $this->import($b, 'commit');
        $this->assertSame('2', (string) $db->one("SELECT COUNT(*) c FROM stories WHERE slug IN ('from-a', 'from-b')")['c'], 'post id 10 on two sites is two stories');
        $this->assertSame('2', (string) $db->one("SELECT COUNT(*) c FROM import_map WHERE legacy_table = 'fcn_story'")['c']);
        $this->assertNotNull($db->one("SELECT 1 FROM import_map WHERE legacy_id = 'a.example.org/10'"));
        $this->assertNotNull($db->one("SELECT 1 FROM import_map WHERE legacy_id = 'b.example.org/10'"));
        $this->assertNotNull($db->one("SELECT 1 FROM legacy_urls WHERE params = 'p=10&post_type=fcn_story'"), 'the fallback row keeps the bare id');
    }

    /** The order array names 20, 21, 22; 21 is a draft (dropped); 23 is an
     *  orphan of the array (linked but unlisted). Positions must read 1, 2,
     *  3 with no gap where the dropped chapter was. */
    public function test_orphans_and_dropped_chapters_keep_positions_contiguous(): void
    {
        $db = $this->readyDb();
        $xml = Wxr::channel([
            Wxr::item(['id' => 10, 'type' => 'fcn_story', 'slug' => 'gappy', 'title' => 'Gappy', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => serialize([20, 21, 22])]]),
            Wxr::chapter(20, 10, ['title' => 'A']),
            Wxr::chapter(21, 10, ['title' => 'B', 'status' => 'draft']),
            Wxr::chapter(22, 10, ['title' => 'C']),
            Wxr::chapter(23, 10, ['title' => 'D']),
        ]);
        $p = $this->write($xml);
        $this->import($p, 'commit');
        $rows = $db->all("SELECT position, title FROM chapters
                          WHERE story_id = (SELECT id FROM stories WHERE slug = 'gappy') ORDER BY position");
        $this->assertSame([[1, 'A'], [2, 'C'], [3, 'D']], array_map(static fn(array $r): array => [(int) $r['position'], $r['title']], $rows));
    }

    /** Serialized meta is unserialized with allowed_classes: false: hostile
     *  payloads become garbage (null), no object is ever instantiated, and
     *  the import degrades to orphan-append instead of failing. */
    public function test_hostile_meta_never_instantiates_objects(): void
    {
        $db = $this->readyDb();
        $evil = 'O:8:"EvilTest":0:{}';
        $xml = Wxr::channel([
            Wxr::item(['id' => 11, 'type' => 'fcn_story', 'slug' => 'evil-meta', 'title' => 'Evil Meta', 'content' => '',
                'meta' => ['fictioneer_story_rating' => 'Teen', 'fictioneer_story_status' => 'Ongoing',
                           'fictioneer_story_chapters' => $evil,
                           'fictioneer_story_co_authors' => $evil,
                           'fictioneer_seo_fields' => $evil]]),
            Wxr::chapter(100, 11, ['title' => 'Fine']),
        ]);
        $p = $this->write($xml);
        $out = $this->import($p, 'commit');
        $this->assertStringNotContainsString('EvilTest', $out . var_export($db->all('SELECT * FROM stories'), true) . var_export($db->all('SELECT * FROM chapters'), true));
        $this->assertSame('1', (string) $db->one("SELECT COUNT(*) c FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = 'evil-meta')")['c'], 'the chapter still lands (as an orphan of the garbage array, position 1)');
        $this->assertSame('1', (string) $db->one("SELECT position FROM chapters WHERE title = 'Fine'")['position']);
    }

    /** WXR files never declare a DOCTYPE: one at the import layer means the
     *  file is not what it claims, and the run refuses before any pass. */
    public function test_doctype_is_refused_at_the_import_layer(): void
    {
        $this->readyDb();
        $p = $this->write('<?xml version="1.0"?><!DOCTYPE rss><rss version="2.0"><channel></channel></rss>');
        $this->expectException(RuntimeException::class);
        $this->import($p, 'dry-run');
    }
}
