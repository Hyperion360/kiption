<?php // app/Features/Wordfilter/WordfilterController.php
namespace App\Features\Wordfilter;
use App\{Adminness, CachePurge, Lang};
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};

/** /wordfilter: the owner edits the list of words members may not use in
 *  reviews, comments, messages, pennames and bios. Admin only, POST + CSRF. */
final class WordfilterController
{
    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app,
    ) {}

    #[AuthAttr]
    public function index(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        return $this->page(null, null);
    }

    #[AuthAttr] #[Post]
    public function save(): Response|string
    {
        $gate = Adminness::requireAdmin($this->db, $this->session);
        if ($gate instanceof Response) return $gate;
        $raw = $this->request->postStr('words');
        if (!mb_check_encoding($raw, 'UTF-8')) return new Response($this->page(Lang::t('wordfilter.err_utf8'), null), 422);
        $words = WordFilter::parse($raw);
        foreach ($words as $w) {
            if (mb_strlen($w) > WordFilter::MAX_WORD_LENGTH) {
                return new Response($this->page(Lang::t('wordfilter.err_too_long', ['max' => WordFilter::MAX_WORD_LENGTH]), null), 422);
            }
            if (preg_match('/[<>"]/', $w) === 1) {
                return new Response($this->page(Lang::t('wordfilter.err_chars'), null), 422);
            }
        }
        if (count($words) > WordFilter::MAX_ENTRIES) {
            return new Response($this->page(Lang::t('wordfilter.err_too_many', ['max' => WordFilter::MAX_ENTRIES]), null), 422);
        }
        $this->db->begin();
        try {
            $this->db->query('DELETE FROM word_filter');
            foreach ($words as $w) $this->db->query('INSERT OR IGNORE INTO word_filter (word) VALUES (?)', [$w]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        WordFilter::forget();
        CachePurge::run($this->app, $this->db);
        return $this->page(null, Lang::t('wordfilter.saved'));
    }

    private function page(?string $error, ?string $saved): string
    {
        $title = Lang::t('wordfilter.heading');
        $words = WordFilter::words($this->db);
        return $this->view->render('wordfilter/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/wordfilter')->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'csrf' => $this->session->csrfToken(),
            'loggedIn' => true,
            'isAdmin' => true,
            'words' => $words, 'error' => $error, 'saved' => $saved,
        ]);
    }
}
