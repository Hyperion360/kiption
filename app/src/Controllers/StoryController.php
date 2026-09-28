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
        // The tags blob arrives type-keyed per row; decode + ksort + sort so
        // the render never depends on aggregation internals (the TOC discipline).
        $tags = [];
        foreach (json_decode((string) $story['tags_blob'], true) ?: [] as $t) {
            $tags[(string) $t['type']][] = (string) $t['name'];
        }
        foreach ($tags as &$names) {
            sort($names, SORT_STRING);
        }
        unset($names);
        ksort($tags);
        unset($story['tags_blob']);
        $reviewRows = json_decode((string) $story['reviews_blob'], true) ?: [];
        $replyRows = json_decode((string) $story['replies_blob'], true) ?: [];
        // A full 200-row blob means the reply cap may have dropped older replies
        // of the window roots; the overflow note must fire on that alone (finding 14).
        $repliesDropped = count($replyRows) >= 200;
        $repliesByRoot = []; // both blobs arrive newest-first; replies bucket onto their root
        foreach ($replyRows as $r) {
            $r['is_author_reply'] = (int) $r['user_id'] === (int) $story['author_id'];
            $repliesByRoot[(int) $r['root_id']][] = $r;
        }
        $reviews = [];
        foreach ($reviewRows as $r) {
            $r['id'] = (int) $r['id'];
            $r['rating'] = $r['rating'] === null ? null : (int) $r['rating'];
            $r['replies'] = $repliesByRoot[(int) $r['id']] ?? [];
            $reviews[] = $r;
        }
        unset($story['reviews_blob'], $story['replies_blob']);
        $head = $this->head()
            ->withTitle(\App\Lang::t('story.title_by', ['title' => $story['title'], 'name' => $story['penname']]))
            ->withDescription($story['meta_description'] ?? $story['summary'])
            ->withCanonical('/story/view/' . $story['slug'])
            ->withArticle($story['created_at'], $story['updated_at']);
        $head = $this->applySyndication($head, $story);
        $hasPart = [];
        foreach ($chapters as $c) {
            $hasPart[] = ['@type' => 'CreativeWork', 'position' => $c['position'], 'name' => $c['title']];
        }
        $head = $head->withJsonLd($this->bookJsonLd($head, $story, $hasPart));
        $rendered = $this->view->render('story/view', [
            'title' => \App\Lang::t('story.title_by', ['title' => $story['title'], 'name' => $story['penname']]),
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
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
            'repliesDropped' => $repliesDropped,
            'series' => $seriesLinks,
            'coauthors' => $coauthors,
            'tags' => $tags,
            'amCoauthor' => $me !== 0 && in_array($me, array_map(static fn (array $c): int => (int) $c['i'], $coauthors), true),
            'csrf' => $me !== 0 ? $this->session->csrfToken() : null,
        ]);
        // External-canonical stories deindex locally: meta noindex plus the
        // X-Robots-Tag header, the belt-and-suspenders idiom whose header also
        // keeps the deindexed page out of the static layer.
        return $head->noindex
            ? (new Response($rendered, 200))->withHeader('X-Robots-Tag', 'noindex')
            : $rendered;
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
        $chapterTitle = $story['ch_title'] !== '' && $story['ch_title'] !== null ? $story['ch_title'] : \App\Lang::t('story.chapter_n', ['n' => $position]);
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => \App\Lang::t('story.gate_heading'),
                'head' => $this->head()->withTitle(\App\Lang::t('story.gate_heading'))->withCanonical($this->request->path),
                'theme' => \App\Theme::current($this->request),
                'navFile' => (string) $this->app->config('nav_file', ''),
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
        $readTitle = \App\Lang::t('story.chapter_page_title', ['n' => $position, 'chapter' => $chapterTitle, 'story' => $story['title']]);
        $head = $this->head()
            ->withTitle($readTitle)
            ->withDescription($story['meta_description'] ?? $story['summary'])
            ->withCanonical('/story/read/' . $slug . '/' . $position)
            ->withArticle($story['created_at'], $story['updated_at']);
        $head = $this->applySyndication($head, $story); // the deindex covers chapter pages too (finding 10)
        $head = $head->withJsonLd($this->bookJsonLd($head, $story, [
            ['@type' => 'CreativeWork', 'position' => $position, 'name' => $chapterTitle],
        ]));
        $rendered = $this->view->render('story/read', [
            'title' => $readTitle,
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
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
        // Same deindex wrap as the story view: an external canonical strips the
        // chapter page from the index too, or half the story stays indexable.
        return $head->noindex
            ? (new Response($rendered, 200))->withHeader('X-Robots-Tag', 'noindex')
            : $rendered;
    }

    /** The whole-work reading view, which doubles as the print view: every
     *  validated chapter on one page behind the SAME gates as chapter reads
     *  (the age cookie for adult stories, the SQL restricted gate for guests).
     *  noindex ALWAYS with the canonical link suppressed: chapters are the
     *  canonical reading units (recorded ruling), so the whole view never
     *  offers itself as one; the syndication branch still applies so an
     *  external canonical keeps its deindex (og:url) inheritance. Records NO
     *  reading progress (note 15: a whole read has no single position) and is
     *  NOT whitelisted in the static cache (size; live-rendered; the
     *  framework page cache applies as usual for cookieless GETs). */
    public function whole(string $slug): Response|string
    {
        if (($r = \App\Features::guard('exports')) !== null) return $r;
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $story = $this->repo->wholeWork($slug, $me);
        if ($story === null) return new Response('Page not found', 404);
        $chapters = [];
        foreach (json_decode((string) $story['chapters_blob'], true) ?: [] as $c) {
            $chapters[(int) $c['position']] = ['position' => (int) $c['position'], 'title' => (string) $c['title'], 'content' => (string) $c['content'], 'word_count' => (int) $c['word_count']];
        }
        ksort($chapters);
        unset($story['chapters_blob']);
        if ($chapters === []) return new Response('Page not found', 404); // nothing validated to read
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => \App\Lang::t('story.gate_heading'),
                'head' => $this->head()->withTitle(\App\Lang::t('story.gate_heading'))->withCanonical($this->request->path),
                'theme' => \App\Theme::current($this->request),
                'navFile' => (string) $this->app->config('nav_file', ''),
                'path' => $this->request->path,
                'story' => $story,
                'returnTo' => '/story/whole/' . $slug,
            ]);
        }
        $wholeTitle = \App\Lang::t('story.whole_page_title', ['title' => $story['title']]);
        $head = $this->head()
            ->withTitle($wholeTitle)
            ->withDescription($story['meta_description'] ?? $story['summary'])
            ->withCanonical('/story/whole/' . $story['slug'])
            ->withArticle($story['created_at'], $story['updated_at'])
            ->withNoindex()
            ->withCanonicalSuppressed();
        $head = $this->applySyndication($head, $story); // the deindex state covers the whole surface too
        $rendered = $this->view->render('story/whole', [
            'title' => $wholeTitle,
            'head' => $head,
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'story' => $story,
            'chapters' => array_values($chapters),
        ]);
        // noindex is unconditional, so the X-Robots-Tag belt rides every render.
        return (new Response($rendered, 200))->withHeader('X-Robots-Tag', 'noindex');
    }

    /** The whole-work exports: the standalone HTML document or the EPUB,
     *  behind the read() gates verbatim (validated + not-deleted + the
     *  CAST'd restricted gate in wholeWork's SQL, the age cookie for adult
     *  stories - the download IS reading). Both formats reuse wholeWork's
     *  single query (finding 16: a one-query render; the html variant is a
     *  QueryBudgetTest pages() row). File names derive from the story slug
     *  only, which the repository's regex already confines to [a-z0-9-], so
     *  the Content-Disposition filename needs no further escaping. Exports
     *  are rate-unlimited: they are renders with the same gates as reading
     *  (recorded ruling). */
    public function download(string $slug, string $format): Response|string
    {
        if (($r = \App\Features::guard('exports')) !== null) return $r;
        $me = $this->request->cookies !== [] ? (int) ($this->session->get('user_id') ?? 0) : 0;
        $story = $this->repo->wholeWork($slug, $me);
        if ($story === null) return new Response('Page not found', 404);
        $chapters = [];
        foreach (json_decode((string) $story['chapters_blob'], true) ?: [] as $c) {
            $chapters[(int) $c['position']] = ['position' => (int) $c['position'], 'title' => (string) $c['title'], 'content' => (string) $c['content'], 'word_count' => (int) $c['word_count']];
        }
        ksort($chapters);
        unset($story['chapters_blob']);
        if ($chapters === []) return new Response('Page not found', 404); // nothing validated to export
        if ((int) $story['is_adult'] === 1 && ($this->request->cookies['age_ok'] ?? null) === null) {
            return $this->view->render('story/gate', [
                'title' => \App\Lang::t('story.gate_heading'),
                'head' => $this->head()->withTitle(\App\Lang::t('story.gate_heading'))->withCanonical($this->request->path),
                'theme' => \App\Theme::current($this->request),
                'navFile' => (string) $this->app->config('nav_file', ''),
                'path' => $this->request->path,
                'story' => $story,
                'returnTo' => '/story/download/' . $slug . '/' . $format,
            ]);
        }
        if (!in_array($format, ['html', 'epub'], true)) return new Response('Page not found', 404);
        $baseUrl = rtrim((string) $this->app->config('base_url', ''), '/');
        if ($format === 'epub') {
            // The cover rides along as bytes (basename-confined read), so the
            // builder itself never touches the filesystem.
            $cover = $this->coverBytes($story);
            if ($cover !== null) {
                [$story['cover_data'], $story['cover_type'], $story['cover_ext']] = $cover;
            }
            $body = (new \App\Export\Epub())->build($story, array_values($chapters), $baseUrl);
            $type = 'application/epub+zip';
        } else {
            $body = (new \App\Export\Html())->build($story, array_values($chapters), $baseUrl);
            $type = 'text/html; charset=utf-8';
        }
        return (new Response($body, 200))
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $story['slug'] . '.' . $format . '"')
            ->withHeader('Content-Length', (string) strlen($body));
    }

    /** The cover bytes for the EPUB, basename-confined to the uploads dir:
     *  the column stores a public /uploads/{name}.{ext} path, so only the
     *  BASENAME ever reaches the filesystem (no traversal, no absolute
     *  escape) and only known image extensions map to a media type. A file
     *  that is missing or unreadable skips gracefully; the export still
     *  builds without the cover.
     *  @return array{0: string, 1: string, 2: string}|null [bytes, MIME type, extension] */
    private function coverBytes(array $story): ?array
    {
        $path = (string) ($story['cover_path'] ?? '');
        if ($path === '') return null;
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                  'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset($types[$ext])) return null;
        $dir = (string) (($this->app->config('uploads', []) ?? [])['dir'] ?? '');
        if ($dir === '') return null;
        $file = rtrim($dir, '/') . '/' . basename($path);
        if (!is_file($file)) return null;
        $data = @file_get_contents($file);
        return $data === false || $data === '' ? null : [$data, $types[$ext], $ext];
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
        [$title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language, $roundRobin, $giftTo, $tagIds] = $this->storyInput();
        if ($title === '') {
            return new Response($this->renderForm($this->authoring()->formData(null, $this->uid()), null, 'Title is required.', null), 422);
        }
        if (!$this->validRating($ratingId)) {
            return new Response($this->renderForm($this->authoring()->formData(null, $this->uid()), null, 'Choose a rating.', null), 422);
        }
        [$id, $slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->authoring()->createStory(
            $this->uid(), $title, $summary, $notes, $ratingId, $categoryIds, $this->autoValidates(), $restricted, $language, $roundRobin, $giftTo, $tagIds);
        $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, [], $challengeSlugs);
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
        [$title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language, $roundRobin, $giftTo, $tagIds] = $this->storyInput();
        [$canonicalUrl, $crosspostUrl, $syndicationError] = $this->syndicationInput();
        if (!$this->validRating($ratingId)) {
            return new Response($this->renderForm($this->authoring()->formData($slug, $this->uid()), null, 'Choose a rating.', null), 422);
        }
        if ($syndicationError !== null) {
            return new Response($this->renderForm($this->authoring()->formData($slug, $this->uid()), null, $syndicationError, null), 422);
        }
        try {
            [$newSlug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->authoring()->updateStory(
                $slug, $this->uid(), $title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language,
                $roundRobin, $giftTo, $canonicalUrl, $crosspostUrl, $tagIds);
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        // The list rider (finding 4): a title change re-renders the blob, a
        // restricted flip changes what guests see on public lists containing
        // the story. Caller-side lookup: Cache stays DB-free.
        $listSlugs = (new \App\Repositories\ListsRepository($this->db))->publicListSlugsForStory($this->storyId($newSlug));
        $this->staticCache()->purgeStory($newSlug, $cats, $seriesSlugs, $authorSlug, $listSlugs, $challengeSlugs);
        if ($newSlug !== $slug) {
            $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, $listSlugs, $challengeSlugs); // old URLs' files too
        }
        return Response::redirect('/story/edit/' . $newSlug);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug): Response
    {
        try {
            [$slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->authoring()->deleteStory($slug, $this->uid());
        } catch (\RuntimeException) {
            return new Response('Page not found', 404);
        }
        // A soft-deleted story leaves every public list's guest render (the rider).
        $listSlugs = (new \App\Repositories\ListsRepository($this->db))->publicListSlugsForStory($this->storyId($slug));
        $this->staticCache()->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, $listSlugs, $challengeSlugs);
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
        [$tagGroups, $selectedTags] = \App\Repositories\AuthoringRepository::tagGroups($rows);
        $chapters = [];
        if ($story !== null && ($story['h'] ?? null) !== null && $story['h'] !== '[]') {
            $chapters = json_decode((string) $story['h'], true) ?: [];
            usort($chapters, static fn(array $x, array $y): int => (int) $x['position'] <=> (int) $y['position']);
        }
        $formTitle = $story === null ? \App\Lang::t('story.new_heading') : \App\Lang::t('story.edit_heading');
        return $this->view->render('story/form', [
            'title' => $formTitle,
            'head' => $this->head()->withTitle($formTitle)
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'story' => $story === null ? null : [
                'slug' => (string) $editSlug, 'title' => $story['b'], 'summary' => $story['c'],
                'notes' => $story['d'], 'rating_id' => $story['f'], 'completed' => $story['g'],
                'restricted' => (int) $story['i'],
                'language' => (string) $story['j'],
                'round_robin' => (int) $story['p'],
                'gift_to' => (string) ($story['q'] ?? ''),
                'canonical_url' => (string) ($story['n'] ?? ''),
                'crosspost_url' => (string) ($story['o'] ?? ''),
            ],
            'selectedCategories' => $story === null ? [] : array_filter(explode(',', (string) ($story['e'] ?? '')), 'strlen'),
            'categories' => $categories,
            'ratings' => $ratings,
            'tagGroups' => $tagGroups,
            'selectedTags' => $selectedTags,
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

    /** The story's id for the purge rider's list lookup; 0 when the row is
     *  gone (a hard miss that publicListSlugsForStory turns into no slugs). */
    private function storyId(string $slug): int
    {
        return (int) ($this->db->one('SELECT id FROM stories WHERE slug = ?', [$slug])['id'] ?? 0);
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
        // Config-injected dir when present (the AdminstoriesController/Importer
        // pattern), the tree's public/cache otherwise: tests pin
        // through-controller purges without ever writing into the real dir.
        return new \App\StaticCache\Cache((string) (($this->app->config('static_cache', []) ?? [])['dir'] ?? dirname(__DIR__, 3) . '/public/cache'));
    }

    /** @return array{string,string,string,int,array,bool,bool,string,bool,string,array} */
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
            // Value-based, not isset (finding 8's lesson): an unchecked box
            // omits the key, and a forged non-'1' value must read as off.
            ($post['round_robin'] ?? '') === '1',
            substr(trim((string) ($post['gift_to'] ?? '')), 0, 120),
            // The tags idiom is the categories idiom: numeric-only, unknown
            // ids drop inside writeTags's INSERT..SELECT WHERE id IN.
            array_values(array_filter((array) ($post['tags'] ?? []), 'is_numeric')),
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

    /** The two syndication fields share the support_url rules: http(s) only and
     *  200 characters max (the form's maxlength is client-side only), plus the
     *  one-state-at-a-time law. @return array{0: string, 1: string, 2: ?string}
     *  canonical, cross-post, error (null when the pair is valid). */
    private function syndicationInput(): array
    {
        $canonical = trim((string) ($this->request->post['canonical_url'] ?? ''));
        $crosspost = trim((string) ($this->request->post['crosspost_url'] ?? ''));
        foreach ([$canonical, $crosspost] as $url) {
            if ($url !== '' && (strlen($url) > 200 || !preg_match('#^https?://#', $url))) {
                return ['', '', 'Syndication URLs must start with http:// or https:// (max 200 characters).'];
            }
        }
        if ($canonical !== '' && $crosspost !== '') {
            return ['', '', 'Choose one syndication state: fill either the canonical URL or the cross-post URL, not both.'];
        }
        return [$canonical, $crosspost, null];
    }

    /** The three syndication states shape the Head: an external canonical wins
     *  and deindexes the local page; a cross-post suppresses the canonical link
     *  (og:url keeps the self URL). Shared by the story view and chapter reads
     *  so the state applies to every page of the story. */
    private function applySyndication(\App\Seo\Head $head, array $story): \App\Seo\Head
    {
        if ((string) ($story['canonical_url'] ?? '') !== '') {
            return $head->withCanonicalUrl((string) $story['canonical_url'])->withNoindex();
        }
        if ((string) ($story['crosspost_url'] ?? '') !== '') {
            return $head->withCanonicalSuppressed();
        }
        return $head;
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
