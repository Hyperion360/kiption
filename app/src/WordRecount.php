<?php // app/src/WordRecount.php
declare(strict_types=1);
namespace App;

/** Recounts stored word totals with the current rule (Markdown::wordCount),
 *  for databases whose chapters were counted under the older rule. Writes only
 *  rows whose number changes, never touches updated_at, and is safe to re-run. */
final class WordRecount
{
    private const BATCH = 200;

    public function __construct(private \Kip\Database $db) {}

    /** @return array{chapters:int,stories:int} how many chapter and story totals changed */
    public function run(): array
    {
        $chapters = 0;
        $after = 0;
        while (($rows = $this->db->all('SELECT id, content, word_count FROM chapters WHERE id > ? ORDER BY id LIMIT ' . self::BATCH, [$after])) !== []) {
            $this->db->begin();
            foreach ($rows as $r) {
                $n = Markdown::wordCount((string) $r['content']);
                if ($n !== (int) $r['word_count']) {
                    $this->db->query('UPDATE chapters SET word_count = ? WHERE id = ?', [$n, $r['id']]);
                    $chapters++;
                }
                $after = (int) $r['id'];
            }
            $this->db->commit();
        }
        // The same rollup the editor uses: a story's total is the sum of its chapters.
        $this->db->query('UPDATE stories SET word_count = COALESCE((SELECT SUM(word_count) FROM chapters WHERE story_id = stories.id), 0)
                          WHERE word_count IS NOT COALESCE((SELECT SUM(word_count) FROM chapters WHERE story_id = stories.id), 0)');
        $stories = (int) $this->db->one('SELECT changes() AS n')['n'];
        return ['chapters' => $chapters, 'stories' => $stories];
    }
}
