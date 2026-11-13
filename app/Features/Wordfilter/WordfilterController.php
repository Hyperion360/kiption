<?php // app/Features/Wordfilter/WordfilterController.php
namespace App\Features\Wordfilter;
use App\{AdminScreen, CachePurge, Lang};
use Kip\Http\Response;
use Kip\Routing\{Auth as AuthAttr, Post};

/** /wordfilter: the owner edits the list of words members may not use in
 *  reviews, comments, messages, pennames and bios. Admin only, POST + CSRF. */
final class WordfilterController extends AdminScreen
{
    #[AuthAttr]
    public function index(): Response|string
    {
        if ($r = $this->deny()) return $r;
        return $this->form(null, null);
    }

    #[AuthAttr] #[Post]
    public function save(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $raw = $this->request->postStr('words');
        if (!mb_check_encoding($raw, 'UTF-8')) return new Response($this->form(Lang::t('wordfilter.err_utf8'), null), 422);
        $words = WordFilter::parse($raw);
        foreach ($words as $w) {
            if (mb_strlen($w) > WordFilter::MAX_WORD_LENGTH) {
                return new Response($this->form(Lang::t('wordfilter.err_too_long', ['max' => WordFilter::MAX_WORD_LENGTH]), null), 422);
            }
            if (preg_match('/[<>"]/', $w) === 1) {
                return new Response($this->form(Lang::t('wordfilter.err_chars'), null), 422);
            }
        }
        if (count($words) > WordFilter::MAX_ENTRIES) {
            return new Response($this->form(Lang::t('wordfilter.err_too_many', ['max' => WordFilter::MAX_ENTRIES]), null), 422);
        }
        $this->atomically(function () use ($words): void {
            $this->db->query('DELETE FROM word_filter');
            foreach ($words as $w) $this->db->query('INSERT OR IGNORE INTO word_filter (word) VALUES (?)', [$w]);
        });
        WordFilter::forget();
        CachePurge::run($this->app, $this->db);
        \App\ActivityLog::by($this->db, $this->session, 'wordfilter.save', count($words) . ' words');
        return \App\Saved::to('/wordfilter');
    }

    /** "Try a sentence": run the saved list over a line the owner types and
     *  say what a member would be told. Nothing is stored or logged. */
    #[AuthAttr] #[Post]
    public function try(): Response|string
    {
        if ($r = $this->deny()) return $r;
        $sentence = mb_substr($this->request->postStr('sentence'), 0, 500);
        if (!mb_check_encoding($sentence, 'UTF-8')) $sentence = '';
        return $this->form(null, null, $sentence);
    }

    private function form(?string $error, ?string $saved, ?string $sentence = null): string
    {
        $tried = $sentence === null || trim($sentence) === '' ? null
            : ['sentence' => $sentence, 'word' => WordFilter::find($this->db, $sentence)];
        return $this->page('wordfilter/index', 'wordfilter.heading', '/wordfilter',
            ['words' => WordFilter::words($this->db), 'error' => $error, 'saved' => $saved, 'tried' => $tried]);
    }
}
