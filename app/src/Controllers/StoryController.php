<?php // app/src/Controllers/StoryController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, View};
use App\Repositories\StoryRepository;

final class StoryController
{
    public function __construct(
        private View $view,
        private Request $request,
        private StoryRepository $repo,
        private App $app,
    ) {}

    public function view(string $slug): Response|string
    {
        $story = $this->repo->findStoryBySlug($slug);
        if ($story === null) return new Response('Page not found', 404);
        $chapters = [];
        foreach (json_decode((string) $story['chapters_blob'], true) ?: [] as $c) {
            $chapters[(int) $c['position']] = ['position' => (int) $c['position'], 'title' => (string) $c['title'], 'word_count' => (int) $c['word_count']];
        }
        ksort($chapters);
        unset($story['chapters_blob']);
        $head = $this->head()
            ->withTitle($story['title'] . ' by ' . $story['penname'])
            ->withDescription($story['meta_description'] ?? $story['summary'])
            ->withCanonical('/story/view/' . $story['slug'])
            ->withArticle($story['created_at'], $story['updated_at']);
        $hasPart = [];
        foreach ($chapters as $c) {
            $hasPart[] = ['@type' => 'CreativeWork', 'position' => $c['position'], 'name' => $c['title']];
        }
        $head = $head->withJsonLd($this->bookJsonLd($head, $story, $hasPart));
        return $this->view->render('story/view', [
            'title' => $story['title'] . ' by ' . $story['penname'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapters' => array_values($chapters),
        ]);
    }

    public function read(string $slug, string $n = '1'): Response|string
    {
        $position = max(1, (int) $n);
        $story = $this->repo->findStoryWithChapter($slug, $position);
        if ($story === null) return new Response('Page not found', 404);
        $positions = array_values(array_filter(array_map('intval', explode('~', (string) $story['positions_blob'])), static fn(int $p): bool => $p > 0));
        sort($positions);
        if ($positions === [] || !in_array($position, $positions, true) || ($story['ch_title'] === null && $story['ch_content'] === null)) {
            return new Response('Page not found', 404);
        }
        $chapterTitle = $story['ch_title'] !== '' && $story['ch_title'] !== null ? $story['ch_title'] : 'Chapter ' . $position;
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => 'Content warning',
                'head' => $this->head()->withTitle('Content warning')->withCanonical($this->request->path),
                'theme' => \App\Theme::current($this->request),
                'path' => $this->request->path,
                'story' => $story,
                'returnTo' => '/story/read/' . $slug . '/' . $position,
            ]);
        }
        $total = count($positions);
        $prev = null; $next = null;
        foreach ($positions as $pn) {
            if ($pn < $position) $prev = $pn;
            if ($next === null && $pn > $position) $next = $pn;
        }
        $head = $this->head()
            ->withTitle('Chapter ' . $position . ': ' . $chapterTitle . ' - ' . $story['title'])
            ->withDescription($story['meta_description'] ?? $story['summary'])
            ->withCanonical('/story/read/' . $slug . '/' . $position)
            ->withArticle($story['created_at'], $story['updated_at']);
        $head = $head->withJsonLd($this->bookJsonLd($head, $story, [
            ['@type' => 'CreativeWork', 'position' => $position, 'name' => $chapterTitle],
        ]));
        return $this->view->render('story/read', [
            'title' => 'Chapter ' . $position . ': ' . $chapterTitle . ' - ' . $story['title'],
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapter' => [
                'title' => $story['ch_title'], 'notes_before' => $story['ch_notes_before'],
                'content' => $story['ch_content'], 'notes_after' => $story['ch_notes_after'],
                'word_count' => $story['ch_word_count'],
            ],
            'position' => $position,
            'total' => $total,
            'prev' => $prev,
            'next' => $next,
        ]);
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** Book node shared by view (full TOC) and read (current chapter only). */
    private function bookJsonLd(\App\Seo\Head $head, array $story, array $hasPart): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Book',
            'name' => $story['title'],
            'author' => $story['penname'],
            'url' => $head->url('/story/view/' . $story['slug']),
            'hasPart' => $hasPart,
        ];
    }

}
