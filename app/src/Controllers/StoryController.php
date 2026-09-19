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
        $chapters = [];
        foreach (explode('~', (string) $story['chapters_blob']) as $chunk) {
            if ($chunk === '') continue;
            [$pos, $title, $words] = explode('|', $chunk, 3);
            $chapters[(int) $pos] = ['position' => (int) $pos, 'title' => $title, 'word_count' => (int) $words];
        }
        ksort($chapters);
        unset($story['chapters_blob']);
        return $this->view->render('story/view', [
            'title' => $story['title'] . ' by ' . $story['penname'],
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapters' => array_values($chapters),
        ]);
    }

    public function read(string $slug, string $n = '1'): Response|string
    {
        $story = $this->repo->findStoryBySlug($slug);
        if ($story === null) return new Response('Page not found', 404);
        $position = (int) $n;
        $positions = array_values(array_filter(array_map('intval', explode('~', (string) $story['chapters_blob'])), static fn(int $p): bool => $p > 0));
        sort($positions);
        $total = count($positions);
        if ($total === 0 || !in_array($position, $positions, true)) {
            return new Response('Page not found', 404);
        }
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => 'Content warning',
                'theme' => \App\Theme::current($this->request),
                'path' => $this->request->path,
                'story' => $story,
                'returnTo' => '/story/read/' . $slug . '/' . $position,
            ]);
        }
        $row = $this->repo->findStoryWithChapter($slug, $position);
        if ($row === null || $row['ch_title'] === null && $row['ch_content'] === null) {
            return new Response('Page not found', 404);
        }
        $prev = null; $next = null;
        foreach ($positions as $p) { if ($p < $position) $prev = $p; if ($next === null && $p > $position) $next = $p; }
        return $this->view->render('story/read', [
            'title' => 'Chapter ' . $position . ': ' . ($row['ch_title'] !== '' ? $row['ch_title'] : 'Chapter ' . $position) . ' - ' . $story['title'],
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story,
            'chapter' => [
                'title' => $row['ch_title'], 'notes_before' => $row['ch_notes_before'],
                'content' => $row['ch_content'], 'notes_after' => $row['ch_notes_after'],
                'word_count' => $row['ch_word_count'],
            ],
            'position' => $position,
            'total' => $total,
            'prev' => $prev,
            'next' => $next,
        ]);
    }

}
