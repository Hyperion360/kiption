<?php // app/Features/Browse/BrowseController.php
namespace App\Features\Browse;
use Kip\{App, Http\Request, Http\Response, Session, View};
use App\Repositories\StoryRepository;
use App\Repositories\UserRepository;

final class BrowseController
{
    public function __construct(
        private View $view,
        private Request $request,
        private App $app,
        private StoryRepository $stories,
        private UserRepository $users,
        private Session $session,
    ) {}

    public function index(): string
    {
        // Same regex the story form stores by: junk reads as unfiltered, like
        // the junk page param. The query string makes these pages cache-ineligible.
        $language = trim((string) ($this->request->get['language'] ?? ''));
        if ($language !== '' && !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language)) {
            $language = '';
        }
        // The cookie-gated $me idiom (plan review finding 13: never a bare
        // session read, or the cookieless cacheable path starts a session) and
        // the mute gate (finding 9: flag off disables the filtering, not just
        // the buttons).
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        return $this->view->render('browse/index', [
            'title' => \App\Lang::t('browse.heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('browse.heading'))->withCanonical('/browse'),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'loggedIn' => $me !== 0,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'categories' => $this->stories->categoriesWithCounts(),
            'language' => $language,
            'langStories' => $language === '' ? [] : $this->stories->storiesInLanguage($language, \App\Features::on('mute') ? $me : 0),
        ]);
    }

    public function recent(): string
    {
        [$perPage, $offset] = $this->paginate();
        $page = $this->page();
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $stories = $this->stories->recentStories($perPage, $offset, \App\Features::on('mute') ? $me : 0);
        $items = [];
        foreach ($stories as $i => $s) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => '/story/view/' . $s['slug'], 'name' => $s['title']];
        }
        return $this->view->render('browse/recent', [
            'title' => \App\Lang::t('browse.recent_heading'),
            'head' => $this->head()->withTitle(\App\Lang::t('browse.recent_heading'))
                ->withCanonical($this->request->path)
                ->withJsonLd(['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items]),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'loggedIn' => $me !== 0,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'stories' => $stories,
            'page' => $page,
            'baseUrl' => '/browse/recent',
        ]);
    }

    public function category(string $slug): Response|string
    {
        [$perPage, $offset] = $this->paginate();
        $page = $this->page();
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $stories = $this->stories->storiesInCategory($slug, $perPage, $offset, \App\Features::on('mute') ? $me : 0);
        $categoryTitle = \App\Lang::t('browse.category_title', ['name' => $slug]);
        $head = $this->head()->withTitle($categoryTitle)
            ->withCanonical($this->request->path)
            ->withJsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => \App\Lang::t('nav.home'), 'item' => '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => \App\Lang::t('browse.heading'), 'item' => '/browse'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $categoryTitle, 'item' => '/browse/category/' . $slug],
                ],
            ]);
        $data = [
            'title' => $categoryTitle,
            'head' => $stories === [] ? $head->withNoindex() : $head,
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'loggedIn' => $me !== 0,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'stories' => $stories,
            'page' => $page,
            'baseUrl' => '/browse/category/' . $slug,
            // Feed autodiscovery: this category's Atom feed (the layout line's
            // idiom, rendered by browse/recent only when set). Gated here at
            // the controller (finding 9b): feeds off passes no feedHref, so
            // the view never renders a link to a 404 route.
            'feedHref' => \App\Features::on('feeds') ? '/feed/category/' . $slug : null,
        ];
        // Empty category pages have no unique content to rank; belt (meta) and
        // suspenders (header) so no cache or crawler ever indexes them.
        return $stories === []
            ? (new Response($this->view->render('browse/recent', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('browse/recent', $data);
    }

    /** The member directory. The router already whitelists [a-z0-9_-] segments,
     *  so $letter arrives lowercase if it arrives at all; the documented
     *  coercion rule: a bare single [a-z] is itself, otherwise the segment is
     *  read by its FIRST character (that char when it is [a-z], the '0'
     *  non-letter bucket when it is a digit or underscore), matching the
     *  repository's bucket semantics. '?beta=1' is a query-string surface:
     *  cache-ineligible by the queryless rule and additionally noindexed
     *  (meta + X-Robots-Tag, the empty-category precedent). */
    public function authors(string $letter = ''): Response|string
    {
        if (($r = \App\Features::guard('directory')) !== null) return $r;
        $letter = trim($letter);
        if ($letter !== '' && !preg_match('/^[a-z]$/', $letter)) {
            $letter = preg_match('/^[a-z]/', $letter) ? $letter[0] : '0';
        }
        $betaOnly = ($this->request->get['beta'] ?? '') === '1';
        // The directory never hides rows (the recorded ruling: it lists authors,
        // not stories); the mute BUTTON rides its rows for members. $me is the
        // cookie-gated idiom, never a bare session read, so the cookieless
        // cacheable path starts no session (plan review finding 13).
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        [$perPage, $offset] = $this->paginate();
        $members = $this->users->authorsDirectory($letter === '' ? null : $letter, $betaOnly, $perPage, $offset);
        $canonical = $letter === '' ? '/browse/authors' : '/browse/authors/' . $letter;
        $label = $letter === '' ? \App\Lang::t('browse.authors') : \App\Lang::t('browse.authors_letter', ['letter' => strtoupper($letter)]);
        $head = $this->head()->withTitle($label)->withCanonical($canonical)
            ->withDescription($betaOnly
                ? \App\Lang::t('browse.meta_beta')
                : \App\Lang::t('browse.meta_authors'));
        $data = [
            'title' => $label,
            'head' => $betaOnly ? $head->withNoindex() : $head, // faceted pages are not canonical content
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'members' => $members,
            'letter' => $letter,
            'beta' => $betaOnly,
            'page' => $this->page(),
            'baseUrl' => $canonical,
            'me' => $me,
            // The ChallengesController idiom: a member render carries the token,
            // a cookieless render carries the empty string and grows no session.
            'csrf' => $me !== 0 ? $this->session->csrfToken() : '',
            'loggedIn' => $me !== 0,
        ];
        return $betaOnly
            ? (new Response($this->view->render('browse/authors', $data), 200))->withHeader('X-Robots-Tag', 'noindex')
            : $this->view->render('browse/authors', $data);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** Coerced page param: junk, zero and negatives become page 1. */
    private function page(): int
    {
        return max(1, (int) ($this->request->get['page'] ?? 1));
    }

    /** Overflow guard: (page - 1) * perPage must stay an int, or a hostile
     *  page=99999999999999999999 makes the offset a float and the repository
     *  TypeError turns the listing into a 500. Cap page so it cannot. */
    private function paginate(): array
    {
        $perPage = max(1, (int) $this->app->config('items_per_page', 20));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage));
        return [$perPage, ($page - 1) * $perPage];
    }
}
