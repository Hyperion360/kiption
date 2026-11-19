<?php // app/Features/Search/SearchController.php
namespace App\Features\Search;
use Kip\{App, Http\Request, View};
use App\Repositories\SearchRepository;

final class SearchController
{
    public function __construct(
        private View $view,
        private Request $request,
        private \Kip\Session $session, // finding 9: the StoryController::view idiom,
        private App $app,              // NOT a Request->session property (none exists)
        private \Kip\Database $db,     // App\Viewer::id's epoch re-validation
        private SearchRepository $search,
    ) {}

    /** GET /search: one budget-1 statement returns the filter taxonomies AND
     *  the paged, gated results (searchWithTaxonomies). The query string
     *  makes the surface cache-ineligible by the queryless rule; it also
     *  renders noindex (meta + X-Robots-Tag) because query surfaces are not
     *  canonical content. Junk filters coerce, never 500. */
    public function index(): \Kip\Http\Response|string
    {
        if (($r = \App\Features::guard('search')) !== null) return $r;
        $raw = $this->request->get['q'] ?? '';
        $q = is_string($raw) && mb_check_encoding($raw, 'UTF-8')
            ? mb_substr(trim($raw), 0, 200, 'UTF-8') : '';
        $tags = \App\Repositories\TagFilter::fromQuery($this->request->get);
        $language = $this->request->get['language'] ?? '';
        if (!is_string($language)) $language = '';
        $filters = [
            'category' => \App\Repositories\CategoryFilter::slugs($this->request->get['category'] ?? []),
            'rating_id' => $this->positiveId('rating'),
            'completed' => match ($this->request->get['completed'] ?? '') { '1' => true, '0' => false, default => null },
            'language' => preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D', $language) ? $language : null,
            'tag' => $tags->include ?: null, 'xtag' => $tags->exclude ?: null,
            'tags' => implode(',', $tags->names), 'xtags' => implode(',', $tags->excludeNames),
            // Pass normalized names intact to the shared predicate and count fold.
            'tag_filter' => $tags,
            'character' => $this->positiveId('character'),
        ];
        $sort = ($this->request->get['sort'] ?? '') === 'updated' ? 'updated' : 'relevance';
        [$perPage, $page, $offset] = $this->paginate();
        // the gate binds the member id: a revoked session filters as a guest
        $me = \App\Viewer::id($this->request, $this->session, $this->db);
        // Finding 9: the mute gate rides here in the controller, so flag off
        // disables the filtering everywhere, not just the buttons.
        $live = ($this->request->get['live'] ?? '') === '1';
        // Passage excerpts, drop-one-filter counts and (live only) author and tag
        // suggestions all ride the same statement as the results.
        $extras = ['passage' => $q !== '', 'drops' => \App\Search\ActiveFilters::variants($filters),
            'suggest' => $live ? $q : ''];
        $result = $this->search->searchWithTaxonomies($q, $filters, $perPage, $offset, $me, \App\Features::on('mute') ? $me : 0, $sort, $extras);
        $data = compact('q', 'filters', 'result', 'sort', 'page', 'perPage') + ['chips' => $this->chips($q, $filters, $sort, $result)];
        if ($live) return $this->live($data);
        $head = $this->head()->withTitle(\App\Lang::t('search.heading'))->withCanonical('/search')->withNoindex();
        $out = $this->view->render('search/index', [
            'title' => \App\Lang::t('search.heading'), 'head' => $head, 'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'q' => $q, 'filters' => $filters, 'result' => $result, 'sort' => $sort,
            'ratings' => $result['ratings'], 'categories' => $result['categories'],
            'page' => $page, 'perPage' => $perPage, 'loggedIn' => $me !== 0, 'chips' => $data['chips'],
        ]);
        return (new \Kip\Http\Response($out, 200))->withHeader('X-Robots-Tag', 'noindex');
    }

    /** Names for the active-filter chips, taken from rows the one query already returned. */
    private function chips(string $q, array $filters, string $sort, array $result): array
    {
        $names = ['category' => [], 'rating' => [], 'tag' => [], 'status' => [1 => \App\Lang::t('browse.status_complete'), 0 => \App\Lang::t('browse.status_wip')]];
        foreach ($result['categories'] as $v) $names['category'][$v['slug']] = (string) $v['name'];
        foreach ($result['ratings'] as $v) $names['rating'][(int) $v['id']] = (string) $v['label'];
        foreach (array_merge($result['tags'], $result['selected']) as $v) $names['tag'][(int) $v['id']] = (string) $v['name'];
        return \App\Search\ActiveFilters::chips($q, $filters, $sort, $names);
    }

    /** Same escaped view and gated rows as the ordinary GET response. */
    private function live(array $data): \Kip\Http\Response
    {
        $result = $data['result'];
        $counts = [];
        foreach ($result['categories'] as $v) $counts['category:' . $v['slug']] = (int) $v['count'];
        foreach ($result['ratings'] as $v) $counts['rating:' . $v['id']] = (int) $v['count'];
        foreach (array_merge($result['tags'], $result['selected']) as $v) {
            $counts['tag:' . $v['id']] = (int) $v['count'];
            $counts['xtag:' . $v['id']] = (int) $v['excludedCount'];
        }
        foreach ($result['counts']['status'] as $v => $n) $counts['status:' . $v] = (int) $n;
        $counts['status:any'] = array_sum(array_map('intval', $result['counts']['status']));
        foreach ($result['counts']['language'] as $v => $n) $counts['language:' . $v] = (int) $n;
        $suggestions = [];
        foreach (array_slice($result['rows'], 0, 5) as $row) {
            $suggestions[] = ['group' => 'stories', 'label' => (string) $row['title'], 'sub' => (string) $row['penname'],
                'url' => '/story/view/' . rawurlencode($row['slug'])];
        }
        foreach ($result['authors'] as $a) {
            $suggestions[] = ['group' => 'authors', 'label' => (string) $a['penname'],
                'sub' => $this->storyCount((int) $a['stories']), 'url' => '/user/view/' . rawurlencode((string) $a['slug'])];
        }
        foreach ($result['suggestTags'] as $t) {
            $suggestions[] = ['group' => 'tags', 'label' => (string) $t['name'], 'sub' => (string) (int) $t['count'],
                'url' => '/search?tag%5B%5D=' . (int) $t['id']];
        }
        $message = $result['mode'] === 'none'
            ? \App\Lang::t($data['q'] === '' ? 'search.hint' : 'search.no_match')
            : \App\Lang::t($result['total'] === 1 ? 'search.live_one' : 'search.live_count', ['count' => (int) $result['total']]);
        $body = json_encode(['html' => $this->view->render('search/_results', $data),
            'suggestions' => $suggestions, 'counts' => $counts, 'total' => (int) $result['total'], 'message' => $message],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return (new \Kip\Http\Response($body, 200))->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('Vary', 'Cookie')->withHeader('X-Robots-Tag', 'noindex');
    }

    private function storyCount(int $n): string
    {
        return \App\Lang::t($n === 1 ? 'search.stories_one' : 'search.stories_n', ['count' => $n]);
    }

    private function positiveId(string $key): ?int
    {
        $raw = $this->request->get[$key] ?? '';
        if (!is_string($raw) && !is_int($raw)) return null;
        $raw = (string) $raw;
        return ctype_digit($raw) && strlen($raw) <= 18 && (int) $raw > 0 ? (int) $raw : null;
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
        $raw = $this->request->get['page'] ?? '1';
        return (is_string($raw) || is_int($raw)) && ctype_digit((string) $raw) ? max(1, (int) $raw) : 1;
    }

    /** Overflow guard: (page - 1) * perPage must stay an int, or a hostile
     *  page=99999999999999999999 makes the offset a float and the repository
     *  TypeError turns the listing into a 500. Cap page so it cannot. */
    private function paginate(): array
    {
        $perPage = max(1, min(100, (int) $this->app->config('items_per_page', 20)));
        $page = min($this->page(), intdiv(PHP_INT_MAX, $perPage) - 1);
        return [$perPage, $page, ($page - 1) * $perPage];
    }
}
