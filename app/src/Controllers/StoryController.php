<?php // app/src/Controllers/StoryController.php
namespace App\Controllers;
use Kip\{Database, Http\Request, Http\Response, View};
use App\Repositories\StoryRepository;

final class StoryController
{
    public function __construct(
        private View $view,
        private Request $request,
        private StoryRepository $repo,
    ) {}

    public function view(string $slug): Response|string
    {
        $story = $this->repo->findStoryBySlug($slug);
        if ($story === null) return new Response('Page not found', 404);
        return $this->view->render('story/view', [
            'title' => $story['title'] . ' by ' . $story['penname'],
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapters' => $this->repo->chaptersForStory((int) $story['id']),
        ]);
    }

    public function read(string $slug, string $n = '1'): Response|string
    {
        $story = $this->repo->findStoryBySlug($slug);
        if ($story === null) return new Response('Page not found', 404);
        $position = (int) $n;
        if ($position < 1) return new Response('Page not found', 404);
        $chapters = $this->repo->chaptersForStory((int) $story['id']);
        $total = count($chapters);
        if ($total === 0) return new Response('Page not found', 404);
        $chapter = $this->repo->findChapter((int) $story['id'], $position);
        if ($chapter === null) return new Response('Page not found', 404);
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => 'Content warning',
                'theme' => \App\Theme::current($this->request),
                'path' => $this->request->path,
                'story' => $story,
                'returnTo' => '/story/read/' . $slug . '/' . $position,
            ]);
        }
        return $this->view->render('story/read', [
            'title' => 'Chapter ' . $position . ': ' . $chapter['title'] . ' - ' . $story['title'],
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapter' => $chapter,
            'position' => $position,
            'total' => $total,
        ]);
    }

}
