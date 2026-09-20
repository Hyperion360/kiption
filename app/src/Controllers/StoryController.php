<?php // app/src/Controllers/StoryController.php
namespace App\Controllers;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\StoryRepository;

final class StoryController
{
    public function __construct(
        private View $view,
        private Request $request,
        private StoryRepository $repo,
        private App $app,
        private Database $db,
        private Session $session,
        private Storage $storage,
    ) {}

    public function view(string $slug): Response|string
    {
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $story = $this->repo->findStoryBySlug($slug, $me);
        if ($story === null) return new Response('Page not found', 404);
        $chapters = [];
        foreach (json_decode((string) $story['chapters_blob'], true) ?: [] as $c) {
            $chapters[(int) $c['position']] = ['position' => (int) $c['position'], 'title' => (string) $c['title'], 'word_count' => (int) $c['word_count']];
        }
        ksort($chapters);
        unset($story['chapters_blob']);
        $seriesLinks = json_decode((string) $story['series_blob'], true) ?: [];
        unset($story['series_blob']);
        $coauthors = json_decode((string) $story['coauthors_blob'], true) ?: [];
        unset($story['coauthors_blob']);
        $reviewRows = json_decode((string) $story['reviews_blob'], true) ?: [];
        $repliesByRoot = []; // blob is newest-first, so replies meet their roots only on a second pass
        foreach ($reviewRows as $r) {
            if ($r['parent_id'] === null) continue;
            $r['is_author_reply'] = (int) $r['user_id'] === (int) $story['author_id'];
            $repliesByRoot[(int) $r['parent_id']][] = $r;
        }
        $reviews = [];
        foreach ($reviewRows as $r) {
            if ($r['parent_id'] !== null) continue;
            $r['id'] = (int) $r['id'];
            $r['rating'] = $r['rating'] === null ? null : (int) $r['rating'];
            $r['replies'] = $repliesByRoot[(int) $r['id']] ?? [];
            $reviews[] = $r;
        }
        unset($story['reviews_blob']);
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
            'kudos_count' => (int) $story['kudos_count'],
            'kudos_by_me' => (int) $story['kudos_by_me'],
            'favorite_count' => (int) $story['favorite_count'],
            'favorite_by_me' => (int) $story['favorite_by_me'],
            'following_author' => (int) $story['following_author'],
            'marked_at_me' => $story['marked_at_me'],
            'reviews' => $reviews,
            'review_count' => (int) $story['review_count'],
            'series' => $seriesLinks,
            'coauthors' => $coauthors,
            'amCoauthor' => $me !== 0 && in_array($me, array_map(static fn (array $c): int => (int) $c['i'], $coauthors), true),
            'csrf' => $me !== 0 ? $this->session->csrfToken() : null,
        ]);
    }

    public function read(string $slug, ?string $n = null): Response|string
    {
        if ($n === null || $n === '') {
            // toc_first rides a cookie (the Theme pattern): the bare chapter-1 URL
            // defers to the table of contents for members who chose it. An explicit
            // /story/read/{slug}/{n} never checks the cookie, so the Builder's
            // cached chapter URLs and anonymous chapter-1 reads stay byte-identical.
            if (($this->request->cookies['toc'] ?? '') === '1') {
                return Response::redirect('/story/view/' . $slug);
            }
            $n = '1';
        }
        $position = max(1, (int) $n);
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $story = $this->repo->findStoryWithChapter($slug, $position, $me);
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
        $rendered = $this->view->render('story/read', [
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
        if ($me !== 0) {
            try {
                (new \App\Repositories\EngagementRepository($this->db))->recordProgress($me, (int) $story['id'], (int) $position);
            } catch (\Throwable) {
                // progress must never break a read
            }
        }
        return $rendered;
    }

    #[AuthAttr] #[Post]
    public function mark(string $slug): Response
    {
        $story = $this->db->one('SELECT id FROM stories WHERE slug = ? AND deleted_at IS NULL', [$slug]);
        if ($story === null) return new Response('Page not found', 404);
        (new \App\Repositories\EngagementRepository($this->db))->toggleMark($slug, (int) $this->session->get('user_id'));
        return Response::redirect('/story/view/' . $slug);
    }

    #[AuthAttr]
    public function new(): string
    {
        return $this->renderForm($this->authoring()->formData(null, $this->uid()), null, null, null);
    }

    #[AuthAttr] #[Post]
    public function create(): Response|string
    {
        [$title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language] = $this->storyInput();
        if ($title === '') {
            return new Response($this->renderForm($this->authoring()->formData(null, $this->uid()), null, 'Title is required.', null), 422);
        }
        if (!$this->validRating($ratingId)) {
            return new Response($this->renderForm($this->authoring()->formData(null, $this->uid()), null, 'Choose a rating.', null), 422);
        }
        [$id, $slug, $cats, $seriesSlugs, $authorSlug] = $this->authoring()->createStory(
            $this->uid(), $title, $summary, $notes, $ratingId, $categoryIds, $this->autoValidates(), $restricted, $language);
        $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug);
        return Response::redirect('/story/edit/' . $slug);
    }

    #[AuthAttr]
    public function edit(string $slug): Response|string
    {
        $rows = $this->authoring()->formData($slug, $this->uid());
        $story = null;
        foreach ($rows as $r) {
            if ($r['k'] === 's') { $story = $r; break; }
        }
        if ($story === null) return new Response('Page not found', 404);
        return $this->renderForm($rows, $story, null, $slug);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug): Response|string
    {
        [$title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language] = $this->storyInput();
        if (!$this->validRating($ratingId)) {
            return new Response($this->renderForm($this->authoring()->formData($slug, $this->uid()), null, 'Choose a rating.', null), 422);
        }
        try {
            [$newSlug, $cats, $seriesSlugs, $authorSlug] = $this->authoring()->updateStory(
                $slug, $this->uid(), $title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeStory($newSlug, $cats, $seriesSlugs, $authorSlug);
        if ($newSlug !== $slug) {
            $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug); // old URLs' files too
        }
        return Response::redirect('/story/edit/' . $newSlug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug): Response
    {
        try {
            [$slug, $cats, $seriesSlugs, $authorSlug] = $this->authoring()->deleteStory($slug, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug);
        $this->staticCache()->purgeAuthors(); // the directory's story counts changed
        return Response::redirect('/account');
    }

    #[AuthAttr] #[Post]
    public function cover(string $slug): Response
    {
        try {
            $story = $this->authoring()->ownStory($slug, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        $file = $this->request->file('cover');
        if ($file === null) {
            return new Response('Cover rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        try {
            $path = $this->storage->put($file);
        } catch (\Kip\UploadException) {
            return new Response('Cover rejected: choose a PNG, JPG, WEBP, or GIF under 2 MiB.', 422);
        }
        $this->db->query('UPDATE stories SET cover_path = ? WHERE id = ?', [$path, $story['id']]);
        [$seriesSlugs, $authorSlug] = $this->authoring()->purgeData((int) $story['id'], (int) $story['author_id']);
        $this->staticCache()->purgeStory($slug, $this->authoring()->categorySlugs((int) $story['id']), $seriesSlugs, $authorSlug);
        return Response::redirect('/story/edit/' . $slug);
    }

    /** @param array[] $rows formData output; $story null on the create form */
    private function renderForm(array $rows, ?array $story, ?string $error, ?string $editSlug): string
    {
        $categories = [];
        $ratings = [];
        $coauthors = [];
        foreach ($rows as $r) {
            if ($r['k'] === 'cat') $categories[] = $r;
            if ($r['k'] === 'r') $ratings[] = $r;
            if ($r['k'] === 'co') $coauthors[] = ['id' => (int) $r['a'], 'penname' => (string) $r['b']];
        }
        $chapters = [];
        if ($story !== null && ($story['h'] ?? null) !== null && $story['h'] !== '[]') {
            $chapters = json_decode((string) $story['h'], true) ?: [];
            usort($chapters, static fn(array $x, array $y): int => (int) $x['position'] <=> (int) $y['position']);
        }
        return $this->view->render('story/form', [
            'title' => $story === null ? 'New story' : 'Edit story',
            'head' => $this->head()->withTitle($story === null ? 'New story' : 'Edit story')
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'path' => $this->request->path,
            'story' => $story === null ? null : [
                'slug' => (string) $editSlug, 'title' => $story['b'], 'summary' => $story['c'],
                'notes' => $story['d'], 'rating_id' => $story['f'], 'completed' => $story['g'],
                'restricted' => (int) $story['i'],
                'language' => (string) $story['j'],
            ],
            'selectedCategories' => $story === null ? [] : array_filter(explode(',', (string) ($story['e'] ?? '')), 'strlen'),
            'categories' => $categories,
            'ratings' => $ratings,
            'chapters' => $chapters,
            // coauthor management is the owner/admin's; l/m ride the 's' row only
            'coauthors' => $coauthors,
            'canManageCoauthors' => $story !== null && ($this->uid() === (int) $story['l'] || (int) $story['m'] === 1),
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'loggedIn' => true,
        ]);
    }

    private function authoring(): \App\Repositories\AuthoringRepository
    {
        return new \App\Repositories\AuthoringRepository($this->db);
    }

    private function uid(): int
    {
        return (int) $this->session->get('user_id');
    }

    private function autoValidates(): bool
    {
        $role = (string) ($this->db->one('SELECT role FROM users WHERE id = ?', [$this->uid()])['role'] ?? 'member');
        return !((bool) $this->app->config('validation_required', true))
            || in_array($role, ['validated_author', 'moderator', 'admin'], true);
    }

    /** POST paths are not budget-bound: a forged or stale rating id is a 422,
     *  never an FK violation surfacing as the kernel's generic 500. */
    private function validRating(int $ratingId): bool
    {
        return $this->db->one('SELECT 1 AS x FROM ratings WHERE id = ?', [$ratingId]) !== null;
    }

    private function staticCache(): \App\StaticCache\Cache
    {
        return new \App\StaticCache\Cache(dirname(__DIR__, 3) . '/public/cache');
    }

    /** @return array{string,string,string,int,array,bool,bool,string} */
    private function storyInput(): array
    {
        $post = $this->request->post;
        $language = trim((string) ($post['language'] ?? ''));
        if ($language !== '' && (strlen($language) > 10 || !preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language))) {
            $language = ''; // junk never reaches the browse filter's regex
        }
        return [
            trim((string) ($post['title'] ?? '')),
            trim((string) ($post['summary'] ?? '')),
            trim((string) ($post['notes'] ?? '')),
            (int) ($post['rating_id'] ?? 0),
            array_values(array_filter((array) ($post['categories'] ?? []), 'is_numeric')),
            isset($post['completed']),
            isset($post['restricted']),
            $language,
        ];
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
