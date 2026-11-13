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
        return $this->form(null, Lang::t('wordfilter.saved'));
    }

    private function form(?string $error, ?string $saved): string
    {
        return $this->page('wordfilter/index', 'wordfilter.heading', '/wordfilter',
            ['words' => WordFilter::words($this->db), 'error' => $error, 'saved' => $saved]);
    }
}
