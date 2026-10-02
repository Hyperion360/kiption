<?php // app/src/Demo.php
namespace App;
use Kip\Database;

/** Demo content for manual testing: `php bin/kip db:demo [--force]`.
 *
 *  Separate from Seeder on purpose: the test suite pins the Seeder fixture
 *  (two stories, exact word counts), so demo data rides on top of it and
 *  never changes it. Every demo account uses an @demo.kiption.test address
 *  and the password "password123"; --force removes exactly the rows this
 *  class created (its accounts, and through them their stories, lists,
 *  messages and engagement, plus its taxonomy, pages, news and nav links)
 *  and builds them again. One transaction: a failure leaves nothing behind.
 *  Prose is assembled from per-story sentence banks with a fixed seed, so
 *  every run produces the same archive. */
final class Demo
{
    public const DOMAIN = 'demo.kiption.test';
    public const PASSWORD = 'password123';

    private Database $db;
    /** @var array<string, int> penname key => user id */
    private array $users = [];
    /** @var array<string, int> story key => story id */
    private array $stories = [];
    /** @var array<string, list<int>> story key => chapter ids by position */
    private array $chapters = [];
    /** @var array<string, int> */
    private array $ratings = [];
    /** @var array<string, int> */
    private array $categories = [];
    /** @var array<string, int> */
    private array $tags = [];

    private const CATEGORIES = [
        'fantasy' => ['Fantasy', 'Lanterns, maps, and the places past the edge of them.'],
        'literary' => ['Literary', 'Quiet stories about people and the weather between them.'],
        'mystery' => ['Mystery', 'Something is missing, and somebody knows where.'],
        'romance' => ['Romance', 'Two people, a long winter, and a lot of bread.'],
        'science-fiction' => ['Science fiction', 'Small moons, long orbits, careful botanists.'],
        'epistolary' => ['Epistolary', 'Stories told in letters, logs, and notes left on tables.'],
        'historical' => ['Historical', 'Before the railway, after the flood.'],
    ];

    private const NEWS = ['Reading lists are live', 'A quieter reader', 'Lighthouse Week starts Monday'];
    private const PAGES = ['guidelines', 'faq'];

    public static function run(Database $db, bool $force = false): array
    {
        return (new self($db))->build($force);
    }

    private function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string, int> what was created, for the CLI summary */
    private function build(bool $force): array
    {
        $existing = (int) $this->db->one('SELECT COUNT(*) c FROM users WHERE email LIKE ?', ['%@' . self::DOMAIN])['c'];
        if ($existing > 0 && !$force) {
            throw new \RuntimeException('Demo data is already loaded (use --force to rebuild it).');
        }
        if ((int) $this->db->one('SELECT COUNT(*) c FROM ratings')['c'] === 0) {
            Seeder::run($this->db); // the base fixture: ratings, General, the two seed stories
        }
        mt_srand(20261002);
        $this->db->begin();
        try {
            if ($existing > 0) $this->clear();
            foreach ($this->db->all('SELECT id, label FROM ratings') as $r) $this->ratings[$r['label']] = (int) $r['id'];
            $this->taxonomy();
            $this->people();
            $this->writeStories();
            $this->series();
            $this->engagement();
            $this->community();
            $this->site();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return [
            'users' => count($this->users),
            'stories' => count($this->stories),
            'chapters' => array_sum(array_map('count', $this->chapters)),
        ];
    }

    /** Remove exactly what a previous run created. Rows whose user column is
     *  ON DELETE SET NULL (reviews, kudos, news comments, reports) are
     *  deleted first so a rebuild never leaves orphans behind. */
    private function clear(): void
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'],
            $this->db->all('SELECT id FROM users WHERE email LIKE ?', ['%@' . self::DOMAIN]));
        $in = implode(',', $ids ?: [0]);
        foreach (["DELETE FROM reviews WHERE user_id IN ({$in})",
                  "DELETE FROM story_kudos WHERE user_id IN ({$in})",
                  "DELETE FROM news_comments WHERE user_id IN ({$in})",
                  "DELETE FROM reports WHERE reporter_id IN ({$in})",
                  "DELETE FROM notifications WHERE actor_id IN ({$in})",
                  "DELETE FROM users WHERE id IN ({$in})"] as $sql) {
            $this->db->query($sql);
        }
        $slugs = "'" . implode("','", array_keys(self::CATEGORIES)) . "'";
        $this->db->query("DELETE FROM categories WHERE slug IN ({$slugs})");
        $this->db->query("DELETE FROM tag_types WHERE name IN ('setting', 'warning')");
        $this->db->query("DELETE FROM tags WHERE name IN ('Slow burn', 'Grief', 'Small town', 'Letters', 'Lighthouse', 'Winter', 'Trains', 'Space station', 'Cozy', 'Found Family', 'slowburn', 'Haunted house')");
        $news = "'" . implode("','", self::NEWS) . "'";
        $this->db->query("DELETE FROM news WHERE title IN ({$news})");
        $pages = "'" . implode("','", self::PAGES) . "'";
        $this->db->query("DELETE FROM pages WHERE slug IN ({$pages})");
        $this->db->query("DELETE FROM nav_links WHERE url IN ('/page/view/guidelines', '/page/view/faq')");
    }

    // ------------------------------------------------------------------ taxonomy

    private function taxonomy(): void
    {
        $pos = 2;
        foreach (self::CATEGORIES as $slug => [$name, $desc]) {
            $this->db->query('INSERT INTO categories (name, slug, description, position) VALUES (?, ?, ?, ?)', [$name, $slug, $desc, $pos++]);
            $this->categories[$slug] = (int) $this->db->lastInsertId();
        }
        $this->categories['general'] = (int) $this->db->one("SELECT id FROM categories WHERE slug = 'general'")['id'];
        foreach ($this->db->all('SELECT t.id, t.name FROM tags t') as $t) $this->tags[$t['name']] = (int) $t['id'];
        $genre = (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'genre'")['id'];
        $content = (int) $this->db->one("SELECT id FROM tag_types WHERE name = 'content'")['id'];
        $this->db->query("INSERT INTO tag_types (name) VALUES ('setting')");
        $setting = (int) $this->db->lastInsertId();
        foreach ([[$content, 'Slow burn'], [$content, 'Grief'], [$setting, 'Small town'], [$genre, 'Letters'],
                  [$setting, 'Lighthouse'], [$setting, 'Winter'], [$setting, 'Trains'], [$setting, 'Space station'],
                  [$content, 'Cozy'], [$genre, 'Haunted house']] as [$type, $name]) {
            $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$type, $name]);
            $this->tags[$name] = (int) $this->db->lastInsertId();
        }
        // Wrangling has work to do: one synonym already merged, one near
        // duplicate still waiting for an editor.
        $this->db->query('INSERT INTO tags (tag_type_id, name, canonical_id) VALUES (?, ?, ?)', [$genre, 'Found Family', $this->tags['Found family']]);
        $this->db->query('INSERT INTO tags (tag_type_id, name) VALUES (?, ?)', [$content, 'slowburn']);
        $this->tags['slowburn'] = (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------------------- people

    private function people(): void
    {
        $now = '2026-10-02T09:00:00Z';
        // key => [penname, local part, role, bio, joined, flags]
        $people = [
            'archivist' => ['The Archivist', 'admin', 'admin', 'Keeps the shelves straight and the lights on.', '2026-01-02', []],
            'hollis' => ['Hollis Crane', 'moderator', 'moderator', 'Reads the queue every morning with coffee.', '2026-02-11', []],
            'wrenfield' => ['wrenfield', 'wrenfield', 'validated_author', "Writes about salt, lamps, and the people who carry them.\n\nCurrently finishing *The Glass Orchard*.", '2026-03-04', ['support' => 'https://ko-fi.com/wrenfield']],
            'oduya' => ['m. oduya', 'oduya', 'validated_author', 'Farmhouses, weather, sisters. Slow updates, long chapters.', '2026-03-19', []],
            'harrowgate' => ['harrowgate', 'harrowgate', 'validated_author', 'Letters, mostly. Sometimes replies.', '2026-04-02', []],
            'juniper' => ['Juniper Vale', 'juniper', 'validated_author', 'Maps of places that drowned, and the apprentices who draw them.', '2026-04-20', []],
            'tobias' => ['Tobias Reyes', 'tobias', 'validated_author', 'Small moons. Careful botanists.', '2026-05-08', []],
            'saoirse' => ['Saoirse Quill', 'saoirse', 'validated_author', 'Trains, snow, and the occasional body in the dining car.', '2026-05-30', []],
            'ines' => ['Ines Varga', 'ines', 'validated_author', 'Bakeries in winter. Co-writes with Saoirse.', '2026-06-14', []],
            'ada' => ['Ada Morrow', 'reader', 'member', 'Reader first. Collects lighthouse stories.', '2026-06-21', ['beta' => 0]],
            'felix' => ['Felix Nakamura', 'felix', 'member', 'Beta reader for fantasy and quiet literary work.', '2026-07-03', ['beta' => 1]],
            'priya' => ['Priya Sen', 'priya', 'member', '', '2026-08-12', []],
            'newcomer' => ['tidewriter', 'newcomer', 'member', 'First story coming soon.', '2026-09-29', ['pending' => 1]],
            'locked' => ['spamlantern', 'locked', 'member', '', '2026-09-30', ['locked' => 1]],
        ];
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        foreach ($people as $key => [$pen, $local, $role, $bio, $joined, $flags]) {
            $this->db->query('INSERT INTO users (email, password_hash, penname, role, is_admin, bio, is_beta, is_locked, created_at, email_verified_at, approved_at, support_url)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $local . '@' . self::DOMAIN, $hash, $pen, $role, $role === 'admin' ? 1 : 0, $bio,
                (int) ($flags['beta'] ?? 0), (int) ($flags['locked'] ?? 0), $joined . 'T10:00:00Z',
                $joined . 'T10:05:00Z', isset($flags['pending']) ? null : $joined . 'T12:00:00Z', $flags['support'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            (new Repositories\UserRepository($this->db))->backfillProfileSlug($id);
            $this->users[$key] = $id;
        }
        unset($now);
        // Ada's preferences: notifications on, the digest for favorites.
        $this->db->query('INSERT INTO user_prefs (user_id, notify_favorite_digest) VALUES (?, 1)', [$this->users['ada']]);
        $this->db->query('INSERT INTO invites (code, created_by) VALUES (?, ?)', ['LANTERN-2026', $this->users['archivist']]);
    }

    // ------------------------------------------------------------------- stories

    private function writeStories(): void
    {
        foreach ($this->catalog() as $key => $s) {
            $this->db->query('INSERT INTO stories (title, slug, summary, notes, author_id, rating_id, completed, featured, validated, round_robin,
                                  created_at, updated_at, is_restricted, language, crosspost_url, gift_to)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $s['title'], $s['slug'], $s['summary'], $s['notes'] ?? '', $this->users[$s['author']], $this->ratings[$s['rating']],
                (int) ($s['complete'] ?? 0), (int) ($s['featured'] ?? 0), (int) ($s['validated'] ?? 1), (int) ($s['round_robin'] ?? 0),
                $s['created'] . 'T08:00:00Z', $s['updated'] . 'T18:30:00Z', (int) ($s['restricted'] ?? 0), $s['language'] ?? '',
                $s['crosspost'] ?? null, $s['gift'] ?? null,
            ]);
            $storyId = (int) $this->db->lastInsertId();
            $this->stories[$key] = $storyId;
            $words = 0;
            foreach ($s['chapters'] as $i => $ch) {
                $pos = $i + 1;
                $body = $this->chapterText($s['bank'], $ch['words'], $ch['lead'] ?? [], $ch['tail'] ?? [], $ch['letter'] ?? null);
                $count = str_word_count(strip_tags($body));
                $validated = (int) ($ch['validated'] ?? ($s['validated'] ?? 1));
                $this->db->query('INSERT INTO chapters (story_id, position, title, notes_before, content, notes_after, validated, word_count, created_at, updated_at, publish_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $storyId, $pos, $ch['title'], $ch['before'] ?? '', $body, $ch['after'] ?? '', $validated, $count,
                    $s['created'] . 'T08:00:00Z', $s['updated'] . 'T18:30:00Z', $ch['publish_at'] ?? null,
                ]);
                $this->chapters[$key][] = (int) $this->db->lastInsertId();
                if ($validated === 1 && !isset($ch['publish_at'])) $words += $count;
            }
            $this->db->query('UPDATE stories SET word_count = ? WHERE id = ?', [$words, $storyId]);
            foreach ($s['categories'] as $cat) {
                $this->db->query('INSERT INTO story_categories (story_id, category_id) VALUES (?, ?)', [$storyId, $this->categories[$cat]]);
            }
            foreach ($s['tags'] ?? [] as $tag) {
                $this->db->query('INSERT INTO story_tags (story_id, tag_id) VALUES (?, ?)', [$storyId, $this->tags[$tag]]);
            }
            foreach ($s['coauthors'] ?? [] as $co) {
                $this->db->query('INSERT INTO coauthors (story_id, user_id) VALUES (?, ?)', [$storyId, $this->users[$co]]);
            }
        }
    }

    /** One chapter of markdown: optional lead paragraphs (canon text), then
     *  paragraphs drawn from the story's sentence bank until the target
     *  length, then optional closing paragraphs. Letters open with a salutation. */
    private function chapterText(string $bank, int $target, array $lead, array $tail, ?string $letter): string
    {
        $sentences = self::BANKS[$bank];
        $paras = [];
        if ($letter !== null) $paras[] = $letter;
        foreach ($lead as $p) $paras[] = $p;
        $words = str_word_count(implode(' ', $paras)) + str_word_count(implode(' ', $tail));
        $last = -1;
        while ($words < $target) {
            $n = mt_rand(3, 6);
            $para = [];
            for ($k = 0; $k < $n; $k++) {
                do { $pick = mt_rand(0, count($sentences) - 1); } while ($pick === $last);
                $last = $pick;
                $para[] = $sentences[$pick];
            }
            $text = implode(' ', $para);
            $paras[] = $text;
            $words += str_word_count($text);
            if (mt_rand(0, 9) === 0) { $paras[] = '* * *'; }
        }
        foreach ($tail as $p) $paras[] = $p;
        if ($letter !== null) $paras[] = mt_rand(0, 1) ? 'Yours, still waiting,' . "\n" . 'M.' : 'With what patience I have left,' . "\n" . 'M.';
        return implode("\n\n", $paras);
    }

    /** @return array<string, array<string, mixed>> */
    private function catalog(): array
    {
        $ch = static fn (string $title, int $words, array $extra = []): array => ['title' => $title, 'words' => $words] + $extra;
        $lanternIII = [
            'By the third morning the road had given up pretending to be a road. It was a pale seam of salt laid across the marsh, and Ilse followed it because there was nothing else to follow.',
            'Her father’s lantern swung at her hip, unlit. He had made her promise not to light it until she could see the sea, and she had promised the way children promise: meaning every word, and none of the consequences.',
            '“You’ll know,” he had said. “The air changes before the water does.”',
            'The air had not changed. It smelled of reeds and wet iron and, faintly, of the bread she had finished the night before and was already regretting. Somewhere to the east a heron lifted out of the grass, slow and offended, and settled again a little further on.',
            'She counted her steps for a while, then stopped counting, then counted the gulls instead. There were none. That was the first thing that worried her.',
            'At noon she sat on the salt and took the lantern into her lap. The glass was cold. Inside, the wick waited the way it had always waited, patient as a held breath, and she thought, not for the first time, that it was heavier than it ought to be.',
        ];
        $lanternIIIEnd = [
            'She walked on until the light went long and copper across the reeds. The salt crunched. The lantern swung. Nothing changed, and then everything did.',
            'When the wind finally turned, it came from the wrong direction entirely, and it tasted of smoke.',
        ];
        $lanternIV = [
            'The town of Vell had been built by people who did not trust the ground. Every house stood on stilts, every street was a bridge, and every bridge had a name painted on it in a hand that grew less steady the further you walked from the harbour.',
            'Ilse arrived at dusk, which was the wrong time to arrive anywhere. The lamplighters were already out, moving from post to post with long brass poles, and every one of them stopped to look at the lantern on her hip.',
            'Nobody said anything. That was worse.',
        ];
        return [
            'lantern' => [
                'title' => 'The Lantern Keeper’s Daughter', 'slug' => 'the-lantern-keepers-daughter', 'author' => 'wrenfield',
                'rating' => 'Teen', 'complete' => 1, 'featured' => 1, 'bank' => 'lantern',
                'summary' => 'Ilse has carried her father’s lantern for as long as she can remember. She has never been allowed to light it. Now he is gone, the sea is three days away, and the lantern is getting heavier.',
                'notes' => 'Thank you to everyone who read this as it posted. The last two chapters were rewritten in September; if you read them before then, they are worth a second look.',
                'categories' => ['fantasy'], 'tags' => ['Fantasy', 'Found family', 'Coming of age', 'Slow burn', 'Maritime'],
                'created' => '2026-05-02', 'updated' => '2026-09-28',
                'chapters' => [
                    $ch('The Keeper’s House', 1900, ['before' => 'Content note: a parent’s death, off the page.']),
                    $ch('What the Tide Left', 2100, ['tail' => ['“The tide never takes anything,” her father used to say. “It only moves things somewhere you haven’t looked.”']]),
                    $ch('The Salt Road', 2300, ['lead' => $lanternIII, 'tail' => $lanternIIIEnd]),
                    $ch('Harbour Lights', 2200, ['lead' => $lanternIV]),
                    $ch('A Map of Small Fires', 2400),
                    $ch('Ferryman', 1700),
                    $ch('The Drowned Bell', 2300, ['after' => 'The bell is based on a real one in a harbour town I will not name. It does still ring at low water.']),
                    $ch('Vell After Dark', 1900),
                    $ch('Glass and Ash', 2100),
                    $ch('The Long Watch', 2000),
                    $ch('The Bar at Low Water', 2000),
                    $ch('What the Lantern Keeps', 2200, ['after' => 'That is the end. Thank you for carrying it this far with her.']),
                ],
            ],
            'weather' => [
                'title' => 'A Quiet Kind of Weather', 'slug' => 'a-quiet-kind-of-weather', 'author' => 'oduya', 'rating' => 'General',
                'bank' => 'weather', 'summary' => 'Two sisters inherit a farmhouse, a debt, and a barometer that predicts arguments instead of rain.',
                'categories' => ['literary'], 'tags' => ['Grief', 'Small town', 'Cozy'], 'created' => '2026-06-10', 'updated' => '2026-10-01',
                'chapters' => [$ch('The Reading of the Will', 2200), $ch('Fair', 2000), $ch('Change', 2400), $ch('Stormy', 2100),
                               $ch('Set Fair', 1800), $ch('Very Dry', 2000, ['before' => 'Short author note: this one is quieter on purpose.'])],
            ],
            'letters' => [
                'title' => 'Nine Letters to the Lighthouse', 'slug' => 'nine-letters-to-the-lighthouse', 'author' => 'harrowgate', 'rating' => 'Teen',
                'complete' => 1, 'bank' => 'letters', 'summary' => 'The keeper never answers. The letters keep coming anyway, and each one is a little less polite.',
                'categories' => ['epistolary'], 'tags' => ['Letters', 'Lighthouse', 'Maritime'], 'created' => '2026-07-01', 'updated' => '2026-09-21',
                'chapters' => array_map(static fn (int $n): array => ['title' => 'Letter ' . $n, 'words' => 700 + 90 * $n,
                    'letter' => ['Dear Keeper,', 'Dear Keeper (again),', 'Keeper,', 'To the Keeper of the North Light,', 'Keeper. Yes, you.', 'Dear Sir or Madam or Lamp,', 'Keeper,', 'Dear Keeper, last time I promise,', 'Dear Ada,'][$n - 1]],
                    range(1, 9)),
            ],
            'cartographer' => [
                'title' => 'The Cartographer’s Apprentice', 'slug' => 'the-cartographers-apprentice', 'author' => 'juniper', 'rating' => 'Teen',
                'bank' => 'maps', 'summary' => 'Pell was hired to copy maps of the drowned coast. Nobody told her the coast was still moving.',
                'categories' => ['fantasy'], 'tags' => ['Fantasy', 'Coming of age', 'Maritime'], 'created' => '2026-06-01', 'updated' => '2026-09-25',
                'chapters' => [$ch('Copying Hand', 1800), $ch('The Inked Shoal', 2000), $ch('Soundings', 1900), $ch('A Coast That Moves', 2100), $ch('Master Ysolde', 1700)],
            ],
            'signal' => [
                'title' => 'Salt and Signal', 'slug' => 'salt-and-signal', 'author' => 'juniper', 'rating' => 'Teen',
                'bank' => 'maps', 'summary' => 'Book two of the drowned coast. The maps are finished. The coast disagrees.',
                'categories' => ['fantasy'], 'tags' => ['Fantasy', 'Maritime'], 'created' => '2026-09-05', 'updated' => '2026-09-30',
                'chapters' => [$ch('Signal Fires', 1600), $ch('The Second Survey', 1800),
                               $ch('Low Light', 1700, ['validated' => 0, 'publish_at' => '2026-10-20T09:00:00Z'])],
            ],
            'orbit' => [
                'title' => 'Orbit of Small Things', 'slug' => 'orbit-of-small-things', 'author' => 'tobias', 'rating' => 'General', 'complete' => 1,
                'bank' => 'orbit', 'summary' => 'A botanist on a station the size of a village tries to grow one apple tree before the supply ship comes back.',
                'categories' => ['science-fiction'], 'tags' => ['Space station', 'Cozy'], 'created' => '2026-07-14', 'updated' => '2026-08-30',
                'chapters' => [$ch('Seed Vault', 1700), $ch('Low Gravity Soil', 1900), $ch('Blossom', 1600), $ch('Supply Ship', 1500)],
            ],
            'train' => [
                'title' => 'The Last Train to Ellery', 'slug' => 'the-last-train-to-ellery', 'author' => 'saoirse', 'coauthors' => ['ines'],
                'rating' => 'Teen', 'complete' => 1, 'bank' => 'train', 'featured' => 1,
                'summary' => 'Snow on the line, eleven passengers, and a conductor who swears there were twelve.',
                'categories' => ['mystery'], 'tags' => ['Trains', 'Winter'], 'created' => '2026-08-01', 'updated' => '2026-09-18',
                'chapters' => [$ch('Departure', 1700), $ch('The Dining Car', 1900), $ch('Twelve Tickets', 2000), $ch('Ellery', 1800), $ch('Platform', 1400)],
            ],
            'winter' => [
                'title' => 'Winter Hours', 'slug' => 'winter-hours', 'author' => 'ines', 'rating' => 'Mature', 'complete' => 1,
                'bank' => 'winter', 'summary' => 'A baker who opens at four in the morning, a night-shift nurse who keeps coming in at five, and one very long winter.',
                'categories' => ['romance'], 'tags' => ['Winter', 'Slow burn', 'Small town'], 'created' => '2026-08-20', 'updated' => '2026-09-26',
                'chapters' => [$ch('Four in the Morning', 1800), $ch('Proofing', 1900), $ch('First Thaw', 2000)],
            ],
            'orchard' => [
                'title' => 'The Glass Orchard', 'slug' => 'the-glass-orchard', 'author' => 'wrenfield', 'rating' => 'General',
                'bank' => 'orchard', 'summary' => 'In Vell, the glassblowers grow their apples in the furnace. One of them has started to rot.',
                'categories' => ['fantasy'], 'tags' => ['Fantasy', 'Small town'], 'created' => '2026-09-10', 'updated' => '2026-10-01',
                'chapters' => [$ch('The Furnace Row', 1600), $ch('Annealing', 1700), $ch('Bloom', 1500, ['validated' => 0])],
            ],
            'rain' => [
                'title' => 'A Short History of Rain', 'slug' => 'a-short-history-of-rain', 'author' => 'oduya', 'rating' => 'General', 'complete' => 1,
                'bank' => 'weather', 'summary' => 'Every rain the farmhouse has ever seen, in the order the roof remembers them.',
                'categories' => ['literary'], 'tags' => ['Grief'], 'created' => '2026-08-08', 'updated' => '2026-08-08',
                'chapters' => [$ch('A Short History of Rain', 1400)],
            ],
            'fennick' => [
                'title' => 'The House on Fennick Lane', 'slug' => 'the-house-on-fennick-lane', 'author' => 'saoirse', 'coauthors' => ['ines', 'harrowgate', 'juniper'],
                'rating' => 'Teen', 'round_robin' => 1, 'bank' => 'house', 'summary' => 'A round robin: four writers, one house, and a door that is never in the same place twice.',
                'categories' => ['mystery', 'fantasy'], 'tags' => ['Haunted house'], 'created' => '2026-09-01', 'updated' => '2026-09-29',
                'chapters' => [$ch('The Door (Saoirse)', 1200), $ch('The Stair (Ines)', 1300), $ch('The Attic (harrowgate)', 1100), $ch('The Cellar (Juniper)', 1400)],
            ],
            'ferry' => [
                'title' => 'For the Night Ferry', 'slug' => 'for-the-night-ferry', 'author' => 'harrowgate', 'rating' => 'General', 'complete' => 1,
                'bank' => 'lantern', 'summary' => 'A short gift story set on the ferry between the marsh and Vell.',
                'gift' => 'wrenfield', 'crosspost' => 'https://example.org/stories/for-the-night-ferry',
                'categories' => ['fantasy'], 'tags' => ['Maritime'], 'created' => '2026-09-14', 'updated' => '2026-09-14',
                'chapters' => [$ch('The Crossing', 1300)],
            ],
            'quietfloor' => [
                'title' => 'The Quiet Floor', 'slug' => 'the-quiet-floor', 'author' => 'tobias', 'rating' => 'Teen', 'restricted' => 1,
                'bank' => 'orbit', 'summary' => 'Members only: the station’s third deck, where nobody is allowed to speak above a whisper.',
                'categories' => ['science-fiction'], 'tags' => ['Space station'], 'created' => '2026-09-20', 'updated' => '2026-09-27',
                'chapters' => [$ch('Whisper Rules', 1500), $ch('Deck Three', 1600)],
            ],
            'faro' => [
                'title' => 'La casa del faro', 'slug' => 'la-casa-del-faro', 'author' => 'harrowgate', 'rating' => 'General', 'complete' => 1,
                'language' => 'es', 'bank' => 'faro', 'summary' => 'Una farera, una tormenta y nueve cartas sin respuesta.',
                'categories' => ['epistolary'], 'tags' => ['Lighthouse'], 'created' => '2026-08-15', 'updated' => '2026-08-22',
                'chapters' => [$ch('La primera carta', 900), $ch('La tormenta', 1000)],
            ],
            'lowtide' => [
                'title' => 'Low Tide', 'slug' => 'low-tide', 'author' => 'newcomer', 'rating' => 'General', 'validated' => 0,
                'bank' => 'lantern', 'summary' => 'A first story, waiting in the validation queue.',
                'categories' => ['general'], 'created' => '2026-09-30', 'updated' => '2026-09-30',
                'chapters' => [$ch('Mudflats', 900)],
            ],
        ];
    }

    // --------------------------------------------------------------------- series

    private function series(): void
    {
        $this->db->query("INSERT INTO series (title, slug, summary, owner_id, membership, created_at) VALUES ('Maps of the Drowned Coast', 'maps-of-the-drowned-coast', 'Pell’s survey of a coast that keeps moving. Read in order.', ?, 'closed', '2026-06-01T08:00:00Z')", [$this->users['juniper']]);
        $maps = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (?, ?, 1, 1), (?, ?, 2, 1)',
            [$maps, $this->stories['cartographer'], $maps, $this->stories['signal']]);
        $this->db->query("INSERT INTO series (title, slug, summary, owner_id, membership, created_at) VALUES ('Stories of Vell', 'stories-of-vell', 'Anything set in the stilt town. Other writers welcome; I confirm new entries.', ?, 'moderated', '2026-09-10T08:00:00Z')", [$this->users['wrenfield']]);
        $vell = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO series_items (series_id, story_id, position, confirmed) VALUES (?, ?, 1, 1), (?, ?, 2, 1), (?, ?, 3, 0)',
            [$vell, $this->stories['lantern'], $vell, $this->stories['orchard'], $vell, $this->stories['ferry']]);
    }

    // ----------------------------------------------------------------- engagement

    private function engagement(): void
    {
        $readers = ['ada', 'felix', 'priya', 'hollis', 'oduya', 'harrowgate', 'juniper', 'tobias', 'saoirse', 'ines', 'wrenfield'];
        $popularity = ['lantern' => 9, 'weather' => 6, 'letters' => 7, 'cartographer' => 5, 'signal' => 2, 'orbit' => 4, 'train' => 8,
                       'winter' => 5, 'orchard' => 3, 'rain' => 3, 'fennick' => 4, 'ferry' => 2, 'quietfloor' => 1, 'faro' => 2];
        $reviewBank = [
            'I read this in one sitting and then went back for the parts I rushed.',
            'The pacing in the middle chapters is exactly right. Nothing wasted.',
            'This line stopped me: I had to put the phone down for a minute.',
            'Lovely, quiet, and a little sad. I will be thinking about the ending for a while.',
            'Small note: chapter two has a typo in the second paragraph. Otherwise perfect.',
            'The setting does so much work without ever announcing itself.',
            'I did not expect to care this much about a barometer.',
            'Saving this to reread in winter.',
            'Every chapter ends exactly where it should.',
            'Came for the premise, stayed for the sentences.',
        ];
        $replyBank = ['Thank you, this made my week.', 'Fixed the typo, thank you for catching it!', 'That line was the first one I wrote, years ago.', 'I am so glad it landed for you.'];
        foreach ($popularity as $key => $weight) {
            $story = $this->stories[$key];
            $author = (int) $this->db->one('SELECT author_id FROM stories WHERE id = ?', [$story])['author_id'];
            // kudos: members plus guest IPs, so the counts reach believable numbers
            foreach ($readers as $r) {
                if ($this->users[$r] !== $author && mt_rand(0, 9) < $weight) {
                    $this->db->query('INSERT INTO story_kudos (story_id, user_id, created_at) VALUES (?, ?, ?)', [$story, $this->users[$r], $this->day(mt_rand(1, 60))]);
                }
            }
            for ($g = 0; $g < $weight * 7; $g++) {
                $this->db->query('INSERT INTO story_kudos (story_id, ip, created_at) VALUES (?, ?, ?)', [$story, '203.0.113.' . (($g * 7 + $story) % 250 + 1), $this->day(mt_rand(1, 60))]);
            }
            // reviews with ratings, some guest reviews, and author replies
            $n = (int) ceil($weight / 2);
            for ($i = 0; $i < $n; $i++) {
                $guest = $i === $n - 1 && $weight > 4;
                $reviewer = $readers[($i * 3 + $story) % count($readers)];
                if (!$guest && $this->users[$reviewer] === $author) $reviewer = 'ada';
                $this->db->query('INSERT INTO reviews (story_id, chapter_id, user_id, guest_name, body, rating, created_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                    $story, $this->chapters[$key][min($i, count($this->chapters[$key]) - 1)], $guest ? null : $this->users[$reviewer],
                    $guest ? 'a lurker' : null, $reviewBank[($i + $story) % count($reviewBank)], mt_rand(0, 2) === 0 ? null : mt_rand(7, 10),
                    $this->day(mt_rand(2, 40)), $guest ? '198.51.100.' . ($story % 200 + 1) : '',
                ]);
                $reviewId = (int) $this->db->lastInsertId();
                if ($i === 0) {
                    $this->db->query('INSERT INTO reviews (story_id, user_id, body, parent_id, created_at) VALUES (?, ?, ?, ?, ?)',
                        [$story, $author, $replyBank[$story % count($replyBank)], $reviewId, $this->day(1)]);
                }
            }
        }
        // page_stats: thirty days of reads, newest days busiest for the
        // popular stories, so Top lists, Trending, Analytics and Stats all
        // have shape
        foreach ($popularity as $key => $weight) {
            foreach ($this->chapters[$key] as $ci => $chapterId) {
                for ($d = 0; $d < 30; $d++) {
                    $reads = (int) max(0, round($weight * (3 - $ci * 0.15) * (1 + (30 - $d) / 30) + mt_rand(-2, 3)));
                    if ($reads > 0) {
                        $this->db->query("INSERT INTO page_stats (day, story_id, chapter_id, reads) VALUES (date('now', ?), ?, ?, ?)",
                            ['-' . $d . ' days', $this->stories[$key], $chapterId, $reads]);
                    }
                }
            }
        }
        $ada = $this->users['ada'];
        // Ada's shelf: three stories in progress, two marked for later
        foreach ([['lantern', 3], ['weather', 2], ['train', 4]] as [$key, $pos]) {
            $this->db->query('INSERT INTO reading_history (user_id, story_id, last_position, updated_at) VALUES (?, ?, ?, ?)', [$ada, $this->stories[$key], $pos, $this->day(1)]);
        }
        foreach (['letters', 'cartographer'] as $key) {
            $this->db->query('INSERT INTO reading_history (user_id, story_id, last_position, marked_at, updated_at) VALUES (?, ?, 1, ?, ?)', [$ada, $this->stories[$key], $this->day(3), $this->day(3)]);
        }
        $this->db->query('INSERT INTO reading_history (user_id, story_id, last_position, updated_at) VALUES (?, ?, 2, ?)', [$this->users['felix'], $this->stories['lantern'], $this->day(2)]);
        // bookmarks with notes (the M5 frame's three rows)
        foreach ([[1, 'The tide line. Use this for the wedding toast.', 5], [2, 'He had made her promise not to light it until she could see the sea.', 3], [4, '', 1]] as [$ci, $note, $days]) {
            $this->db->query('INSERT INTO bookmarks (user_id, story_id, chapter_id, note, created_at) VALUES (?, ?, ?, ?, ?)',
                [$ada, $this->stories['lantern'], $this->chapters['lantern'][$ci], $note, $this->day($days)]);
        }
        $this->db->query('INSERT INTO bookmarks (user_id, story_id, chapter_id, note, created_at) VALUES (?, ?, ?, ?, ?)',
            [$ada, $this->stories['train'], $this->chapters['train'][2], 'Twelve tickets. Count again.', $this->day(2)]);
        // favorites: stories, a series, an author
        foreach (['lantern', 'letters', 'orbit'] as $key) {
            $this->db->query('INSERT INTO favorites (user_id, story_id, created_at) VALUES (?, ?, ?)', [$ada, $this->stories[$key], $this->day(mt_rand(3, 20))]);
        }
        foreach ([['felix', 'lantern'], ['priya', 'lantern'], ['hollis', 'train'], ['felix', 'weather']] as [$who, $key]) {
            $this->db->query('INSERT INTO favorites (user_id, story_id, created_at) VALUES (?, ?, ?)', [$this->users[$who], $this->stories[$key], $this->day(mt_rand(3, 20))]);
        }
        $this->db->query("INSERT INTO favorites (user_id, series_id) VALUES (?, (SELECT id FROM series WHERE slug = 'maps-of-the-drowned-coast'))", [$ada]);
        $this->db->query('INSERT INTO favorites (user_id, author_id) VALUES (?, ?)', [$ada, $this->users['wrenfield']]);
        // follows and a mute
        $this->db->query("INSERT INTO follows (follower_id, author_id, notify_mode) VALUES (?, ?, 'site'), (?, ?, 'email'), (?, ?, 'digest'), (?, ?, 'site')", [
            $ada, $this->users['wrenfield'], $ada, $this->users['harrowgate'], $ada, $this->users['oduya'], $this->users['felix'], $this->users['wrenfield']]);
        $this->db->query('INSERT INTO muted (user_id, author_id) VALUES (?, (SELECT id FROM users WHERE email = ?))', [$ada, 'demo@example.test']);
    }

    // ------------------------------------------------------------------ community

    private function community(): void
    {
        $ada = $this->users['ada'];
        // reading lists: public with notes, private, and another reader's
        $this->db->query("INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, 'Books for a rainy week', 'books-for-a-rainy-week', 'Long, slow, warm. Best with tea.', 1)", [$ada]);
        $rainy = (int) $this->db->lastInsertId();
        foreach ([['weather', 'Start here.'], ['lantern', 'Save the last chapter for the weekend.'], ['rain', ''], ['winter', 'For when the tea runs out.']] as $i => [$key, $note]) {
            $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position, note) VALUES (?, ?, ?, ?)', [$rainy, $this->stories[$key], $i + 1, $note]);
        }
        $this->db->query("INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, 'To reread', 'to-reread', '', 0)", [$ada]);
        $private = (int) $this->db->lastInsertId();
        $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position) VALUES (?, ?, 1), (?, ?, 2)', [$private, $this->stories['letters'], $private, $this->stories['orbit']]);
        $this->db->query("INSERT INTO reading_lists (owner_id, title, slug, summary, is_public) VALUES (?, 'Lighthouses, ranked', 'lighthouses-ranked', 'Every lighthouse story on the archive, best first.', 1)", [$this->users['felix']]);
        $lights = (int) $this->db->lastInsertId();
        foreach (['letters', 'lantern', 'faro', 'ferry'] as $i => $key) {
            $this->db->query('INSERT INTO reading_list_items (list_id, story_id, position) VALUES (?, ?, ?)', [$lights, $this->stories[$key], $i + 1]);
        }
        // messages: two threads, one unread
        $wren = $this->users['wrenfield'];
        foreach ([[$ada, $wren, 'Hi! Is the bell in chapter seven a real place? I think I have been there.', 6, true],
                  [$wren, $ada, 'It is based on one, yes. I will not say where, but you are probably right.', 5, true],
                  [$ada, $wren, 'I knew it. Thank you for the book.', 5, true],
                  [$this->users['felix'], $ada, 'Do you want to beta read the next Juniper Vale chapter with me? She asked for two readers.', 1, false],
                  [$this->users['harrowgate'], $ada, 'Letter nine is for you, by the way. Thank you for reading all of them.', 0, false]] as [$from, $to, $body, $days, $read]) {
            $this->db->query('INSERT INTO messages (sender_id, recipient_id, body, created_at, read_at) VALUES (?, ?, ?, ?, ?)', [$from, $to, $body, $this->day($days), $read ? $this->day($days) : null]);
        }
        // notifications for Ada (reader) and wrenfield (author)
        foreach ([[$ada, 'update', 'lantern', $wren, 1, false], [$ada, 'reply', 'lantern', $wren, 1, false], [$ada, 'pm', null, $this->users['felix'], 1, false],
                  [$ada, 'follow', null, $this->users['felix'], 4, true], [$wren, 'kudos', 'lantern', $ada, 0, false], [$wren, 'review', 'lantern', $ada, 1, false],
                  [$wren, 'favorite', 'lantern', $this->users['priya'], 2, false], [$wren, 'series_submit', 'ferry', $this->users['harrowgate'], 3, false],
                  [$wren, 'follow', null, $ada, 9, true], [$this->users['ines'], 'coauthor', 'train', $this->users['saoirse'], 20, true]] as [$uid, $kind, $key, $actor, $days, $read]) {
            $this->db->query('INSERT INTO notifications (user_id, kind, story_id, actor_id, story_title, read_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $uid, $kind, $key === null ? null : $this->stories[$key], $actor,
                $key === null ? null : (string) $this->db->one('SELECT title FROM stories WHERE id = ?', [$this->stories[$key]])['title'],
                $read ? $this->day($days) : null, $this->day($days),
            ]);
        }
        // moderation queue: two open reports and one resolved
        $this->db->query('INSERT INTO reports (reporter_id, story_id, reason, created_at) VALUES (?, ?, ?, ?)',
            [$this->users['priya'], $this->stories['quietfloor'], 'The summary says members only but chapter two is linked from a public list.', $this->day(1)]);
        $review = (int) $this->db->one('SELECT id FROM reviews WHERE story_id = ? AND parent_id IS NULL ORDER BY id LIMIT 1', [$this->stories['train']])['id'];
        $this->db->query('INSERT INTO reports (reporter_id, review_id, reason, created_at) VALUES (?, ?, ?, ?)', [$this->users['felix'], $review, 'Spoils the ending in the first line.', $this->day(2)]);
        $this->db->query('INSERT INTO reports (reporter_id, story_id, reason, resolved_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$ada, $this->stories['orbit'], 'Wrong category, this is science fiction.', $this->day(10), $this->day(12)]);
        // a challenge with prompts and entries
        $this->db->query("INSERT INTO challenges (title, slug, summary, owner_id, membership, created_at) VALUES ('Lighthouse Week', 'lighthouse-week', 'Seven days, one light, any genre. Entries are confirmed by the host.', ?, 'moderated', ?)", [$this->users['hollis'], $this->day(14)]);
        $ch = (int) $this->db->lastInsertId();
        foreach (['A keeper who refuses to keep.', 'A letter that arrives too late.', 'The light goes out for one night.'] as $i => $p) {
            $this->db->query('INSERT INTO challenge_prompts (challenge_id, position, prompt_text) VALUES (?, ?, ?)', [$ch, $i + 1, $p]);
        }
        foreach ([['letters', 1, 1], ['faro', 2, 1], ['ferry', 3, 0]] as [$key, $pos, $ok]) {
            $this->db->query('INSERT INTO challenge_items (challenge_id, story_id, position, confirmed) VALUES (?, ?, ?, ?)', [$ch, $this->stories[$key], $pos, $ok]);
        }
    }

    // ----------------------------------------------------------------- site bits

    private function site(): void
    {
        $arch = $this->users['archivist'];
        foreach ([[self::NEWS[0], "You can now build **reading lists**, public or private, with a note on every story.\n\nFind them under Reading lists in the footer.", 20],
                  [self::NEWS[1], "The reader got a redesign: three themes (Paper, Sepia, Night), a text panel, bookmarks with notes, and a focus mode.\n\nTell us what you think in the comments.", 6],
                  [self::NEWS[2], "Our first community challenge. Pick a prompt, write a lighthouse, submit it to the challenge page.", 2]] as [$title, $body, $days]) {
            $this->db->query('INSERT INTO news (author_id, title, body, published_at) VALUES (?, ?, ?, ?)', [$arch, $title, $body, $this->day($days)]);
            $newsId = (int) $this->db->lastInsertId();
            foreach (array_slice(['ada' => 'Love this.', 'felix' => 'Night mode is perfect for the train home.', 'priya' => 'Is there a way to export a list?'], 0, $days > 10 ? 1 : 3, true) as $who => $c) {
                $this->db->query('INSERT INTO news_comments (news_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$newsId, $this->users[$who], $c, $this->day(max(0, $days - 1))]);
            }
        }
        $this->db->query("INSERT INTO pages (slug, title, body) VALUES ('guidelines', 'Community guidelines', ?), ('faq', 'Questions', ?)", [
            "## Be kind in reviews\n\nSay what worked before what did not. Spoilers go behind a warning.\n\n## Rate honestly\n\nUse Mature or Explicit for adult content. Readers rely on it.\n\n## Credit your collaborators\n\nAdd co-authors on the story page so both of you get notified.",
            "## How do I bookmark?\n\nOpen any chapter and use Bookmark in the reader bar. Add a note from the Bookmarks tab.\n\n## Can I read offline?\n\nUse EPUB or HTML from any story page.\n\n## Why do some stories ask my age?\n\nMature and Explicit stories show a one-time notice.",
        ]);
        $max = (int) $this->db->one('SELECT COALESCE(MAX(position), 0) m FROM nav_links')['m'];
        $this->db->query('INSERT INTO nav_links (label, url, position) VALUES (?, ?, ?), (?, ?, ?)',
            ['Guidelines', '/page/view/guidelines', $max + 1, 'Questions', '/page/view/faq', $max + 2]);
    }

    /** An ISO timestamp $days before now (UTC), at a steady mid-afternoon time. */
    private function day(int $days): string
    {
        return gmdate('Y-m-d', time() - $days * 86400) . 'T15:' . str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00Z';
    }

    /** Per-story sentence banks: each chapter draws its paragraphs from its
     *  story's bank so the voice holds across the archive. */
    private const BANKS = [
        'lantern' => [
            'The marsh went on the way grief does, flat and patient and without any obvious edge.',
            'Ilse shifted the lantern to her other hip and pretended it had not grown heavier overnight.',
            'Her father had kept the lantern on the hook by the door for nineteen years and never once lit it.',
            'Somewhere behind the reeds a bell rang twice, slowly, as if it were not sure it should.',
            'Marit walked ahead with her brass pole on her shoulder, whistling a tune that had no ending.',
            'The bridges of Vell creaked under every step, each one with its name painted in a different hand.',
            '“You carry it like it might go off,” Marit said, and Ilse did not answer, because it might.',
            'Odo the ferryman counted coins without looking at them and looked at the lantern without counting anything.',
            'The tide had gone out so far that the harbour looked like a sentence somebody had stopped halfway through.',
            'She dreamed of the keeper’s house and woke with salt in her mouth.',
            'Every lamplighter in Vell knew her father’s name, and none of them would say it out loud.',
            'The glass of the lantern fogged when she breathed on it and cleared again, keeping its own counsel.',
            'Tam caught up with them at the third bridge, out of breath and pretending not to be.',
            'There is a kind of quiet that comes before weather, and the whole town held it.',
            'She had promised, and the promise sat in her chest like a stone she had swallowed on purpose.',
            'The smoke came in low over the water, smelling of tar and something sweeter underneath.',
            'Edda Hale’s windows were the only ones in Vell that stayed lit all night.',
            'Ilse counted the posts along the harbour wall: forty-one, which was a strange number for posts.',
            'The ledger in the lantern’s base shifted when she turned too quickly, a whisper of paper against brass.',
            'Gulls finally came on the fourth morning, loud and rude and very welcome.',
            'Marit laughed at something Tam said, and the sound skipped across the water like a flat stone.',
            'Nobody in Vell trusted the ground, and after two days Ilse did not trust it either.',
            'The bell rang again at low water, and this time she was sure she heard her father’s name in it.',
            'She sat at the end of the last bridge with her boots over the edge and waited for the air to change.',
        ],
        'weather' => [
            'The barometer in the hall read Change, which in this house had never once meant rain.',
            'Nell put the kettle on because it was the only argument the two of them had never had.',
            'Bea read the letter from the bank twice and then folded it into a very small square.',
            'Outside, the fields lay brown and patient under a sky that could not make up its mind.',
            'Their mother’s coats still hung by the door, and neither sister would be the first to move them.',
            'The needle swung toward Stormy the moment Bea mentioned selling the north field.',
            'Nell had always been the one who stayed, and Bea had always been the one who called on Sundays.',
            'A crow walked the length of the fence as if it were inspecting it for the bank.',
            'The farmhouse smelled of damp wool and apples, which is to say it smelled of being a child.',
            'They ate supper at opposite ends of a table built for eight.',
            'When the needle finally settled on Fair, both sisters looked at it and then, carefully, at each other.',
            'The debt was not large, exactly; it was only larger than everything they had.',
            'Bea found a jar of buttons in the dresser and spent an hour sorting them by colour for no reason at all.',
            'The rain, when it came, came sideways and did not apologise.',
            'Nell said nothing, which in their family was a full paragraph.',
            'There was a photograph on the stairs of the two of them in matching coats, scowling at the same cloud.',
            'The well pump coughed twice and gave them water the colour of weak tea.',
            'By Thursday the barometer had stopped arguing and simply pointed at Very Dry, as if it were tired.',
        ],
        'letters' => [
            'I am writing again because you did not answer, and I have decided that is a kind of answer.',
            'The light was late last night by eleven minutes; I timed it from my kitchen window.',
            'My grandmother used to say that a keeper who will not wave is a keeper with something to hide.',
            'Enclosed is a pressed flower from the cliff path, in case you have never been allowed down from the tower.',
            'I want to be clear that I am not angry, only curious, and also somewhat angry.',
            'The ferry captain says you have not been seen in town since spring.',
            'If you are reading these and choosing not to reply, I would like to know which ones you liked.',
            'I have started leaving the letters in the stone box at the bottom of the steps, since the post refuses to go up.',
            'Last week the light flickered in a pattern, and I am almost certain it spelled my name.',
            'The gulls have taken to sitting on my windowsill as if they are waiting for your reply too.',
            'I asked at the harbour office who keeps your light, and the clerk went pale and shut the window.',
            'This is my ninth letter, which is more than anyone should write to a lamp.',
            'You may be interested to know the fog came in at four and did not leave until noon.',
            'I have begun to suspect you are not one person at all, but several, taking turns.',
            'Please find attached my complaint, my apology, and a very good recipe for scones.',
        ],
        'maps' => [
            'Pell inked the coastline exactly as the old map showed it, and by morning the ink was wrong.',
            'Master Ysolde kept her best maps under glass, as if they might try to leave.',
            'The shoals on the north chart had moved three fingers to the east overnight.',
            'Every map of the drowned coast came with a date in the corner, and none of them agreed.',
            'Pell learned to take soundings before breakfast, when the water was still honest.',
            'The compass in the workshop pointed at the sea no matter which way you turned it.',
            'Ysolde said a good cartographer draws what is there; a great one draws what is coming.',
            'The apprentices traded rumours about a village that had been drawn off the map entirely.',
            'Salt crusted the window frames and had to be scraped off every evening with a bone knife.',
            'Pell copied the same inlet four times until her hand learned it better than her head.',
            'There was a red line on the oldest map that nobody would explain, and Pell could not stop looking at it.',
            'At low tide you could walk to the old church tower and touch the bell through the mud.',
            'The coast moved again on Tuesday, quietly, the way a sleeper turns over.',
            'She wrote the new soundings in the margin in pencil, because ink had begun to feel arrogant.',
        ],
        'orbit' => [
            'The station turned once every ninety minutes, and Juno had stopped noticing the sunrises somewhere around the four hundredth.',
            'The apple seedling leaned toward the grow lamp like it was trying to remember a sun it had never seen.',
            'Soil in low gravity crumbled upward if you were careless with the trowel.',
            'The supply ship was eleven weeks out, which was three weeks longer than the tomatoes.',
            'Commander Okafor stopped by the greenhouse every evening to ask how the tree was, as if asking after a patient.',
            'Juno kept a notebook of every leaf, numbered, with the date it unfurled.',
            'Through the window the small moon passed, grey and indifferent, its craters full of shadow.',
            'The hydroponic pumps sang a single note that she had started humming along to without meaning to.',
            'On the third deck everyone spoke in whispers, a rule older than anyone could remember.',
            'The first blossom opened during a power dip, as if it had been waiting for the dark.',
            'She pollinated it with a paintbrush from the station school, very carefully, holding her breath.',
            'Somebody had taped a photograph of an orchard on Earth above the airlock, curling at the corners.',
            'The station creaked as it turned, a sound like a ship at anchor in a slow tide.',
        ],
        'train' => [
            'The snow had been falling for six hours when the train stopped between two stations that were not on the map.',
            'The conductor counted the passengers twice and came up with eleven both times, which was the problem.',
            'There were twelve tickets punched in his book, and one of them had no name on it.',
            'Mrs Albery ordered tea for two and drank both cups, looking at the empty seat across from her.',
            'The dining car smelled of coal smoke, wet wool, and a very good beef stew.',
            'Inspector Hale, on holiday and furious about it, put down her book with great reluctance.',
            'Outside, the drifts climbed the windows until the carriage felt like the inside of an egg.',
            'Someone had written ELLERY in the frost on the window of compartment seven, from the outside.',
            'The guard swore the luggage van had been locked since Carlow, and the guard was lying.',
            'A pocket watch stopped at four minutes past nine and was found in the wrong coat.',
            'Everyone had been asleep, or said they had, which on a train is the same thing.',
            'When the plough engine finally reached them at dawn, there were twelve passengers again.',
        ],
        'winter' => [
            'The bakery opened at four, and at five past four every morning, the night nurse came in from the cold.',
            'Marta knew her order before she reached the counter: one rye, one cardamom bun, and the newspaper she never read.',
            'The ovens ticked as they cooled, a small clock nobody had to wind.',
            'Snow piled against the door until opening it was an act of faith.',
            'Lena always sat at the table by the window and watched the street lamps go out one by one.',
            'Flour got everywhere in winter: on the stairs, in the sheets, in Marta’s eyebrows.',
            'They talked about nothing for weeks, which was its own kind of courage.',
            'On the coldest morning of the year Lena did not come, and Marta burned an entire tray of buns.',
            'The first thaw arrived like an apology, late and dripping and sincere.',
            'Some mornings the only sound in the shop was the radiator and two people deciding not to say something.',
            'She kept the cardamom bun warm under a cloth on the mornings Lena was late, and told nobody.',
            'The kiss, when it happened, tasted of sugar and very strong coffee and took them both by surprise.',
        ],
        'orchard' => [
            'In Vell the glassblowers grew their apples in the furnace, one breath at a time.',
            'The orchard on Furnace Row glittered at noon so brightly that the gulls avoided it.',
            'Each apple took nine days to cool, and you could not hurry it any more than you could hurry a real one.',
            'The rotten apple was green at the core, a colour glass should never be.',
            'Aunt Corra tapped every fruit with a silver spoon and listened to the note it sang.',
            'The annealing ovens hummed all night, and the whole street slept in their warmth.',
            'Someone had been walking the rows after dark, leaving footprints in the ash.',
            'A glass apple, dropped, does not break so much as change its mind about being whole.',
            'The bloom came early that year, translucent petals no thicker than breath.',
        ],
        'house' => [
            'The door was on the left side of the hall this morning, which was new.',
            'Every writer who entered the house on Fennick Lane left something behind, usually a sentence.',
            'The stairs counted themselves out loud, and they were never sure of the total.',
            'In the attic there was a trunk full of letters addressed to people who had not been born yet.',
            'The cellar smelled of apples and of rain that had happened a long time ago.',
            'Somebody had painted over the wallpaper, and the wallpaper had painted back.',
            'The house liked visitors in the way a cat likes mice.',
            'By the fourth chapter nobody could agree on how many rooms there were, and the house preferred it that way.',
        ],
        'faro' => [
            'La farera no contestaba nunca, y aun así las cartas seguían llegando.',
            'La tormenta empezó a las cuatro y no se fue hasta el mediodía.',
            'Dejé la carta en la caja de piedra al pie de la escalera, como siempre.',
            'Anoche la luz parpadeó tres veces, y estoy casi segura de que era para mí.',
            'Las gaviotas se sientan en mi ventana como si también esperaran respuesta.',
            'Mi abuela decía que un faro que no saluda esconde algo.',
            'El barquero dice que nadie la ha visto en el pueblo desde la primavera.',
            'Le envío una flor del camino del acantilado, por si nunca la dejan bajar.',
        ],
    ];
}
