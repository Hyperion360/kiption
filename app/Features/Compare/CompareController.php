<?php // app/Features/Compare/CompareController.php
namespace App\Features\Compare;
use App\Marketing\TopPaths;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};

/** The comparison hub: /compare lists every comparison, and each one serves
 *  from its own Page row at a top-level path. Method names are hyphen-FREE
 *  because the router hands the action segment to method_exists() raw
 *  (Router.php:69): /compare/royalroad resolves, /compare/royal-road never
 *  does. The SLUGS keep their hyphens; the PATHS do not. */
final class CompareController
{
    public function __construct(private View $view, private Session $session, private Request $request, private App $app, private Database $db) {}

    public function index(): Response|string
    {
        // One IN-query for the eight rows that exist; a row-less comparison
        // still renders its link, degraded to the slug as its title.
        $slugs = array_map(static fn(array $c): string => $c['slug'], TopPaths::comparisons());
        $found = [];
        if ($slugs !== []) {
            $in = implode(',', array_fill(0, count($slugs), '?'));
            foreach ($this->db->all("SELECT slug, title FROM pages WHERE slug IN ($in)", $slugs) as $row) {
                $found[(string) $row['slug']] = (string) $row['title'];
            }
        }
        $rows = [];
        foreach (TopPaths::comparisons() as $c) {
            $rows[] = [
                'path' => $c['path'],
                'title' => $found[$c['slug']]
                    ?? ucfirst(str_replace('-', ' ', substr($c['slug'], 8))),
            ];
        }
        $title = \App\Lang::t('compare.heading');
        return $this->view->render('compare/index', [
            'title' => $title,
            'head' => \App\Seo\Head::make(
                siteName: (string) $this->app->config('site_name', 'Kiption'),
                ogImage: (string) $this->app->config('og_image', ''),
                baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
            )->withTitle($title)->withCanonical('/compare')->withDescription(\App\Lang::t('compare.meta_description')),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'loggedIn' => \App\Viewer::id($this->request, $this->session, $this->db) !== 0,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'rows' => $rows,
        ]);
    }

    public function efiction(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-efiction');
    }

    public function fictioneer(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-fictioneer');
    }

    public function wattpad(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-wattpad');
    }

    public function webnovel(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-webnovel');
    }

    public function royalroad(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-royal-road');
    }

    public function fanfictionnet(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-fanfiction-net');
    }

    public function ao3(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-ao3');
    }

    public function substack(): Response|string
    {
        return \App\Marketing\MarketingPage::render($this->view, $this->request, $this->db, $this->session, $this->app, 'compare-substack');
    }
}
