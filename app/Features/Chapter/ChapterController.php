<?php // app/Features/Chapter/ChapterController.php
namespace App\Features\Chapter;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\AuthoringRepository;

final class ChapterController
{
    /** A save-key retry only follows a lost response, so keys older than two weeks are dead weight. */
    private const SAVE_KEY_TTL = 14 * 86400;

    private array $formRows = [];
    private array $currentRevisions = [];

    public function __construct(
        private View $view, private Request $request, private Database $db,
        private Session $session, private App $app, private \App\MailGuard $mailer,
    ) {}

    #[AuthAttr]
    public function new(string $slug): Response|string
    {
        if (\App\Posting::paused($this->db, $this->uid())) return \App\Posting::stopPage($this->view, $this->request, $this->app);
        return $this->form($slug, null, null);
    }

    #[AuthAttr]
    public function edit(string $slug, int $position): Response|string
    {
        return $this->form($slug, $position, null);
    }

    #[AuthAttr] #[Post]
    public function create(string $slug): Response|string
    {
        if (\App\Posting::paused($this->db, $this->uid())) {
            return $this->wantsJson() ? $this->json(['error' => \App\Lang::t('error.forbidden')], 403) : \App\Posting::stopPage($this->view, $this->request, $this->app);
        }
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        if (($replay = $this->replay('create', $slug, null)) !== null) return $replay;
        $answers = null;
        if ($this->storyDraftMode($slug, null)) {
            [$answers, $storyError] = $this->storyAnswers();
            if ($storyError !== null) return $this->fail($slug, null, $storyError[0], 422, [], $storyError[1], $storyError[2]);
        }
        if (trim($content) === '') {
            return $this->fail($slug, null, \App\Lang::t('chapter.err_text'), 422, [], '', 'ed-f-content');
        }
        if (($wordError = $this->wordLimitError($content)) !== null) {
            return $this->fail($slug, null, $wordError);
        }
        if (($imageError = $this->imageError('', $content)) !== null) {
            return $this->fail($slug, null, $imageError);
        }
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return $this->fail($slug, null, \App\Lang::t('chapter.publish_invalid'));
        }
        $auto = $this->autoValidates();
        // The round-robin expansion reaches chapter CREATION only (finding 7's
        // scope ruling): a full member may open the gate on rr stories, while
        // update/delete and every story form stay story-side.
        $rr = \App\Features::on('roundrobin');
        // The post-password field (Fictioneer parity): hashed here, stored by
        // the repository; a new chapter was never public, so no purge rider.
        // On a draft story the posted password belongs to the story; the chapter's own access stays as it is.
        $accessPassword = $answers !== null ? '' : trim((string) ($this->request->post['access_password'] ?? ''));
        $land = $this->landing($slug);
        $draft = $this->isDraft();
        // A draft lands back in its own editor (the position is known only once the row exists).
        // A story-draft publish may rename the story, so its landing takes the slug the write settled on.
        $redirect = $draft ? fn (int $chapterId, int $newPosition, string $newSlug = ''): string => \App\Saved::url('/chapter/edit/' . ($newSlug !== '' ? $newSlug : $slug) . '/' . $newPosition, 'draft')
            : ($answers !== null ? fn (int $chapterId, int $newPosition, string $newSlug = ''): string => '/story/edit/' . ($newSlug !== '' ? $newSlug : $slug) : $land);
        $landed = $land;
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs, $liveSlug, $suggestOut] = $this->repo()->createChapter($slug, $this->uid(), $title, $content, $before, $after, $auto, $publishAt, $rr,
                $accessPassword === '' ? null : password_hash($accessPassword, PASSWORD_DEFAULT), $this->saveRecorder('create', $redirect, $landed), $draft, $answers);
            $slug = $liveSlug ?? $slug;
        } catch (\RuntimeException) {
            return $this->notFound(); // non-owned or unknown story, same contract as story writes
        } catch (\PDOException $e) {
            if (($won = $this->replayAfterRace()) !== null) return $won;
            throw $e;
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        $this->flashSuggestRefused($suggestOut);
        \App\ActivityLog::record($this->db, $this->uid(), 'chapter.create', rtrim($slug . ': ' . $title, ': '));
        // Finding 5: a scheduled create never fans out. storyForNotify only
        // checks live_chapters >= 1, so scheduling onto a live story would
        // notify followers about a chapter they cannot read yet.
        if ($auto && $publishAt === null && !$draft) $this->notifyPublish($slug);
        return $this->saved($landed);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug, int $position): Response|string
    {
        if ($this->formRow($slug, $position) === null) return $this->notFound();
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        if (($replay = $this->replay('update', $slug, $position)) !== null) return $replay;
        $answers = null;
        if ($this->storyDraftMode($slug, $position)) {
            [$answers, $storyError] = $this->storyAnswers();
            if ($storyError !== null) return $this->fail($slug, $position, $storyError[0], 422, [], $storyError[1], $storyError[2]);
        }
        if (trim($content) === '') {
            return $this->fail($slug, $position, \App\Lang::t('chapter.err_text'), 422, [], '', 'ed-f-content');
        }
        if (($wordError = $this->wordLimitError($content)) !== null) {
            return $this->fail($slug, $position, $wordError);
        }
        if (($imageError = $this->imageError((string) ($this->formRow($slug, $position)['content'] ?? ''), $content)) !== null) {
            return $this->fail($slug, $position, $imageError);
        }
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return $this->fail($slug, $position, \App\Lang::t('chapter.publish_invalid'));
        }
        $revision = $this->request->post['accept_revision'] ?? ($this->request->post['base_revision'] ?? null);
        if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            return $this->fail($slug, $position, \App\Lang::t('chapter.reload_before_save'), 409);
        }
        // The post-password pair (Fictioneer parity; the repository hashes the
        // raw input and applies the clear-wins trio). A NULL-to-set lock on a
        // live chapter is the delete path's forever-stale case: the pre-write
        // hash decides the deferred re-purge rider below.
        // On a draft story those two fields are the story's, and the chapter keeps its own access.
        $accessPassword = $answers !== null ? '' : trim((string) ($this->request->post['access_password'] ?? ''));
        $accessClear = $answers === null && ($this->request->post['access_clear'] ?? '') === '1';
        $locking = !$accessClear && $accessPassword !== '';
        $old = $this->formRow($slug, $position);
        $draft = $this->isDraft();
        // A live chapter never goes back to draft: readers would lose it. The editor
        // comes back with the writer's text intact and a plain notice; nothing is saved.
        // A stale form falls through so the revision check answers with its 409.
        if ($draft && $this->isLive($old) && hash_equals(\App\ChapterRevision::of($old), $revision)) {
            return $this->fail($slug, $position, \App\Lang::t('chapter.live_no_draft'));
        }
        $editUrl = \App\Saved::url('/chapter/edit/' . $slug . '/' . $position, $draft ? 'draft' : '1');
        $recorded = $answers === null ? $editUrl : fn (int $chapterId, int $newPosition, string $newSlug = ''): string => \App\Saved::url('/chapter/edit/' . ($newSlug !== '' ? $newSlug : $slug) . '/' . $position, $draft ? 'draft' : '1');
        $wasOpen = ($old['access_hash'] ?? null) === null || (string) $old['access_hash'] === '';
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs, $newSlug, $suggestOut] = $this->repo()->updateChapter($slug, $position, $this->uid(), $title, $content, $before, $after, $publishAt, $accessPassword, $accessClear, $revision,
                $this->saveRecorder('update', $recorded, $editUrl), $draft, $this->autoValidates(), $answers);
            if ($newSlug !== null && $newSlug !== '') {
                $slug = $newSlug;
                $editUrl = \App\Saved::url('/chapter/edit/' . $slug . '/' . $position, $draft ? 'draft' : '1');
            }
            $this->flashSuggestRefused($suggestOut ?? []);
        } catch (\App\ChapterChanged $e) {
            // An identical save that raced ahead moved the revision; answer from its recorded key, not a 409.
            if (($won = $this->replayAfterRace()) !== null) return $won;
            $key = $slug . ':' . $position;
            $this->formRows[$key] = array_replace($this->formRows[$key], $e->savedFields());
            $this->currentRevisions[$key] = $e->savedRevision();
            return $this->fail($slug, $position, \App\Lang::t('chapter.changed'), 409);
        } catch (\PDOException $e) {
            if (($won = $this->replayAfterRace()) !== null) return $won;
            $native = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($native & 0xff, [5, 6], true)) throw $e;
            return $this->fail($slug, $position, \App\Lang::t('chapter.busy'), 503, ['Retry-After' => '1']);
        } catch (\RuntimeException) {
            return $this->notFound();
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        \App\ActivityLog::record($this->db, $this->uid(), 'chapter.update', $slug . ' #' . $position);
        if ($locking && $wasOpen) {
            // The delete pattern's deferred rider: a guest render in flight
            // during the synchronous purge can re-store pre-lock prose after
            // it, and a locked chapter is never purged again by later writes.
            $this->app->defer(function () use ($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs): void {
                $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
            });
        }
        $wasLive = (int) ($this->db->one(
            'SELECT validated FROM chapters WHERE story_id = (SELECT id FROM stories WHERE slug = ?) AND position = ?',
            [$slug, $position])['validated'] ?? 0);
        if ($wasLive === 1) $this->notifyPublish($slug); // only a live chapter's edit (or a draft just published) is "news"
        return $this->saved($editUrl);
    }

    #[AuthAttr] #[Post]
    public function delete(string $slug, int $position): Response
    {
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->deleteChapter($slug, $position, $this->uid());
        } catch (\RuntimeException) {
            return new Response(\App\Lang::t('error.not_found'), 404);
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        \App\ActivityLog::record($this->db, $this->uid(), 'chapter.delete', $slug . ' #' . $position);
        return Response::redirect('/story/edit/' . $slug);
    }

    // ---- JSON saves: the editor posts the form with Accept: application/json
    // and a UUID save_key; a retry after a lost response replays the recorded
    // redirect instead of writing a second chapter. Plain form posts are unchanged.

    private ?string $saveKey = null;
    private string $saveHash = '';

    private function wantsJson(): bool
    {
        return str_contains(strtolower((string) $this->request->header('accept')), 'application/json');
    }

    private function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $status,
            $headers + ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    private function notFound(): Response
    {
        return $this->wantsJson() ? $this->json(['error' => \App\Lang::t('error.not_found')], 404) : new Response(\App\Lang::t('error.not_found'), 404);
    }

    /** The refusal as the editor's JSON {error}, or the usual form page. */
    private function fail(string $slug, ?int $position, string $message, int $status = 422, array $headers = [], string $row = '', string $field = ''): Response|string
    {
        if ($this->wantsJson()) return $this->json(['error' => $message] + ($field !== '' || $row !== '' ? ['row' => $row, 'field' => $field] : []), $status, $headers);
        $this->openRow = $row;
        $this->focusField = $field;
        return $this->form($slug, $position, $message, $status, $headers);
    }

    private string $openRow = '';
    private string $focusField = '';

    /** True when the chapter editor is also the story's first-publish form: the story is still a draft. */
    private function storyDraftMode(string $slug, ?int $position): bool
    {
        return (int) ($this->formRow($slug, $position)['story_draft'] ?? 0) === 1;
    }

    /** The posted story answers of a draft story, ready for the repository, or a refusal [message, row, field].
     *  A draft save needs only a rating; a publish also needs a story title. @return array{0: ?array, 1: ?array} */
    private function storyAnswers(): array
    {
        [$title, $summary, $notes, $ratingId, $categoryIds, $completed, $restricted, $language, $roundRobin, $isOriginal, $giftTo, $tagIds,
            $accessPassword, $accessClear, $characterIds, $suggest, $withdraw] = \App\StoryInput::from($this->request->post, 'story_title');
        $draft = $this->isDraft();
        if ($title === '') {
            if (!$draft) return [null, [\App\Lang::t('story.err_title'), 'story', 'ed-f-title']];
            $title = trim((string) ($this->request->post['title'] ?? '')) ?: \App\Lang::t('story.untitled');
        }
        if ($this->db->one('SELECT 1 AS x FROM ratings WHERE id = ?', [$ratingId]) === null) {
            return [null, [\App\Lang::t($draft ? 'story.err_rating_draft' : 'story.err_rating'), 'rating', 'ed-f-rating']];
        }
        if ($this->repo()->lockedViolation($this->uid(), $categoryIds, null)) {
            return [null, [\App\Lang::t('story.err_locked_category'), 'categories', 'ed-f-category']];
        }
        return [['title' => $title, 'summary' => $summary, 'notes' => $notes, 'rating_id' => $ratingId, 'category_ids' => $categoryIds,
            'completed' => $completed, 'restricted' => $restricted, 'language' => $language, 'round_robin' => $roundRobin, 'gift_to' => $giftTo,
            'is_original' => $isOriginal, 'tag_ids' => $tagIds, 'character_ids' => $characterIds, 'suggest' => $suggest, 'withdraw' => $withdraw,
            'access_password' => $accessPassword, 'access_clear' => $accessClear], null];
    }

    /** Every story field that rides the save-key hash, so a retry with a changed story answer is a different request. */
    private function storyHashFields(): array
    {
        $in = \App\StoryInput::from($this->request->post, 'story_title');
        $in[12] = hash('sha256', (string) $in[12]); // the password never sits in the hash input
        return $in;
    }

    private function flashSuggestRefused(array $suggestOut): void
    {
        $lines = [];
        foreach ($suggestOut['refused'] ?? [] as $name => $reason) $lines[] = $name . ' (' . \App\Lang::t('story.suggest_refused_' . $reason) . ')';
        if ($lines !== []) $this->session->set('suggest_refused', $lines);
    }

    /** A saved chapter: the JSON {redirect} for the editor, a 302 for a form. */
    private function saved(string $url): Response
    {
        return $this->wantsJson() ? $this->json(['redirect' => $url]) : Response::redirect($url);
    }

    /** Where a new chapter lands. A pure rr contributor cannot see /story/edit
     *  (owner-gated by construction); ownStory WITHOUT the flag is exactly the
     *  edit page's gate, so the probe cannot drift from the surface it routes to. */
    private function landing(string $slug): string
    {
        try {
            $this->repo()->ownStory($slug, $this->uid());
            return '/story/edit/' . $slug;
        } catch (\RuntimeException) {
            return '/story/view/' . $slug;
        }
    }

    /** JSON save lookup, run after auth, CSRF and the story-access check but
     *  before the mutable-policy checks (word limit, image switch) and the
     *  revision compare, so an identical retry never meets a stale 409.
     *  Null means proceed with a fresh write. */
    private function replay(string $op, string $slug, ?int $position): ?Response
    {
        if (!$this->wantsJson()) return null;
        $key = $this->request->post['save_key'] ?? null;
        if (!is_string($key) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key) !== 1) {
            return $this->json(['error' => \App\Lang::t('chapter.err_save_key')], 422);
        }
        if ($this->formRow($slug, $position) === null) return $this->notFound();
        [$title, $content, $before, $after, $publishAt] = $this->chapterInput();
        $p = $this->request->post;
        $this->saveKey = $key;
        $this->saveHash = hash('sha256', json_encode([$op, $slug, $position, $title, $content, $before, $after, $publishAt,
            hash('sha256', trim((string) ($p['access_password'] ?? ''))), ($p['access_clear'] ?? '') === '1', $this->isDraft(),
            $this->storyDraftMode($slug, $position) ? $this->storyHashFields() : null], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        return $this->recorded();
    }

    private function recorded(): ?Response
    {
        $row = $this->db->one('SELECT request_hash, redirect FROM chapter_save_requests WHERE user_id = ? AND save_key = ?', [$this->uid(), $this->saveKey]);
        if ($row === null) return null;
        return hash_equals((string) $row['request_hash'], $this->saveHash)
            ? $this->json(['redirect' => (string) $row['redirect']])
            : $this->json(['error' => \App\Lang::t('chapter.err_save_key_reused')], 409);
    }

    /** Two identical requests racing: the loser's key insert fails inside its
     *  transaction, which rolls back whole; answer it from the winner's row. */
    private function replayAfterRace(): ?Response
    {
        return $this->saveKey === null ? null : $this->recorded();
    }

    /** The writer's in-transaction hook that records the key, or null for a form post. */
    private function saveRecorder(string $op, string|\Closure $redirect, ?string &$landed = null): ?callable
    {
        // A Closure redirect (a draft create, whose address needs the new position) always needs the hook, key or not.
        if ($this->saveKey === null && !$redirect instanceof \Closure) return null;
        return function (int $chapterId, int $newPosition = 0, string $newSlug = '') use ($op, $redirect, &$landed): void {
            $landed = $redirect instanceof \Closure ? $redirect($chapterId, $newPosition, $newSlug) : $redirect;
            if ($this->saveKey === null) return;
            $this->db->query('DELETE FROM chapter_save_requests WHERE user_id = ? AND created_at < ?', [$this->uid(), date('c', time() - self::SAVE_KEY_TTL)]);
            $this->db->query('INSERT INTO chapter_save_requests (user_id, save_key, request_hash, chapter_id, operation, redirect, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$this->uid(), $this->saveKey, $this->saveHash, $chapterId, $op, $landed, date('c')]);
        };
    }

    /** New image references must come from the author's own library, and none
     *  may be an unresolved editor placeholder. Unchanged ones always pass. */
    private function imageError(string $stored, string $submitted): ?string
    {
        return \App\ChapterChecks::imageError($this->db, $this->uid(), $stored, $submitted);
    }

    private function formRow(string $slug, ?int $position): ?array
    {
        $key = $slug . ':' . ($position === null ? 'new' : (string) $position);
        if (!array_key_exists($key, $this->formRows)) {
            $this->formRows[$key] = $this->repo()->chapterFormData(
                $slug, $position, $this->uid(), \App\Features::on('roundrobin')
            );
        }
        return $this->formRows[$key];
    }

    private function form(string $slug, ?int $position, ?string $error, int $status = 422, array $headers = []): Response|string
    {
        // The flag rides every chapterFormData call; the repository scopes the
        // rr clause to the NEW-chapter branch alone (the edit form stays
        // story-side, matching the update/delete writes it feeds).
        $row = $this->formRow($slug, $position);
        if ($row === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $baseRow = $row;
        $key = $slug . ':' . ($position === null ? 'new' : (string) $position);
        $currentRevision = $position === null ? 'new'
            : ($this->currentRevisions[$key] ?? \App\ChapterRevision::of($baseRow));
        $baseRevision = $currentRevision;
        if ($error !== null) {
            // An error must not turn an old editing page into permission to
            // overwrite. Only the distinct replacement button adopts a token.
            $submitted = $this->request->post['base_revision'] ?? '';
            $baseRevision = $position === null ? 'new'
                : (is_string($submitted) && preg_match('/^[a-f0-9]{64}$/D', $submitted) === 1 ? $submitted : '');
            foreach (['title', 'content', 'notes_before', 'notes_after', 'publish_at'] as $field) {
                $value = $this->request->post[$field] ?? '';
                $row[$field] = is_string($value) ? $value : '';
            }
            $row['access_clear'] = ($this->request->post['access_clear'] ?? null) === '1';
            $row['is_draft'] = $this->isDraft() ? 1 : 0;
        }
        $row['draft'] = (int) ($row['is_draft'] ?? 0);
        $writerProtected = (int) ($baseRow['recovery_protected'] ?? 0) === 1
            || ($error !== null && is_string($this->request->post['access_password'] ?? null)
                && $this->request->post['access_password'] !== '');
        $vars = \App\ChapterEditorView::vars($this->request, $this->session, $this->app, $this->uid(), [
            'title' => $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit_title'),
            'row' => $row, 'baseRow' => $baseRow, 'position' => $position, 'error' => $error,
            'contents' => $this->contents((string) ($baseRow['contents_json'] ?? ''), $position),
            'baseRevision' => $baseRevision, 'currentRevision' => $currentRevision, 'conflict' => isset($this->currentRevisions[$key]),
            'writerChapter' => $position === null ? 'new:' . $baseRow['story_id'] : 'chapter:' . $baseRow['chapter_id'],
            'writerCheckUrl' => $position === null ? '/chapter/new/' . $slug : '/chapter/edit/' . $slug . '/' . $position,
            'writerProtected' => $writerProtected,
        ]);
        $extra = [];
        if ((int) ($baseRow['story_draft'] ?? 0) === 1) {
            $j = json_decode((string) ($baseRow['story_json'] ?? ''), true) ?: [];
            $saved = ['title' => (string) ($j['title'] ?? ''), 'summary' => (string) ($j['summary'] ?? ''), 'notes' => (string) ($j['notes'] ?? ''),
                'rating_id' => $j['rating_id'] ?? null, 'completed' => (int) ($j['completed'] ?? 0), 'restricted' => (int) ($j['restricted'] ?? 0),
                'language' => (string) ($j['language'] ?? \App\Languages::DEFAULT), 'round_robin' => (int) ($j['round_robin'] ?? 0),
                'is_original' => (int) ($j['is_original'] ?? 0), 'gift_to' => (string) ($j['gift_to'] ?? '')];
            $cats = array_filter(explode(',', (string) ($j['cat_ids'] ?? '')), 'strlen');
            $extra = \App\StoryFormVars::build(\App\Repositories\AuthoringRepository::storyFormRows((string) $baseRow['story_json']), $cats, $saved,
                $error !== null ? \App\StoryInput::retry($this->request->post, 'story_title') : null);
            $lines = $this->session->get('suggest_refused', []);
            if ($lines !== []) $this->session->forget('suggest_refused');
            $extra += ['mode' => 'draft-story', 'openRow' => $this->openRow, 'focusField' => $this->focusField, 'moreOpen' => $this->openRow === 'more',
                'suggestRefused' => is_array($lines) ? $lines : []];
            if ($error !== null) $vars['story']['story_title'] = trim((string) ($this->request->post['story_title'] ?? ''));
        }
        $html = $this->view->render('chapter/form', $vars + $extra);
        return $error === null ? $html : new Response($html, $status, $headers);
    }

    /** The Contents panel rows, decoded from the form query's JSON column (no second statement).
     *  Each: position, title, words, publish_at (string or null), draft (0/1), plus the status
     *  computed here: state is draft, scheduled (publish_at still in the future, UTC) or published;
     *  status is its label; current marks the chapter being edited. */
    private function contents(string $json, ?int $position): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $rows = [];
        foreach (json_decode($json === '' ? '[]' : $json, true) ?: [] as $c) {
            $publishAt = ($c['publish_at'] ?? '') === '' ? null : (string) $c['publish_at'];
            $draft = (int) ($c['draft'] ?? 0);
            $state = $draft === 1 ? 'draft' : ($publishAt !== null && $publishAt > $now ? 'scheduled' : 'published');
            $rows[] = ['position' => (int) $c['position'], 'title' => (string) $c['title'], 'words' => (int) $c['words'],
                'publish_at' => $publishAt, 'draft' => $draft, 'state' => $state,
                'status' => \App\Lang::t('chapter.status_' . $state), 'current' => (int) $c['position'] === $position];
        }
        return $rows;
    }

    /** Live means readers can open it now: not a draft, and either already validated (a stray
     *  later schedule is inert on a live chapter) or no schedule still in the future (UTC). */
    private function isLive(array $row): bool
    {
        if ((int) ($row['is_draft'] ?? 0) === 1) return false;
        if ((int) ($row['chapter_validated'] ?? 0) === 1) return true;
        $at = (string) ($row['publish_at'] ?? '');
        return $at === '' || $at <= gmdate('Y-m-d\TH:i:s\Z');
    }

    /** The posted draft switch: only the literal "1" saves a draft; absent means publish per the schedule. */
    private function isDraft(): bool
    {
        return ($this->request->post['draft'] ?? '') === '1';
    }

    private function repo(): AuthoringRepository { return new AuthoringRepository($this->db); }
    private function uid(): int { return (int) $this->session->get('user_id'); }

    private function autoValidates(): bool
    {
        $role = (string) ($this->db->one('SELECT role FROM users WHERE id = ?', [$this->uid()])['role'] ?? 'member');
        return !((bool) $this->app->config('validation_required', true))
            || in_array($role, ['validated_author', 'moderator', 'admin'], true);
    }

    private function purge(string $slug, array $cats, array $seriesSlugs = [], string $authorSlug = '', array $challengeSlugs = []): void
    {
        // Config-injected dir when present (the StoryController pattern), the
        // tree's public/cache otherwise: tests pin through-controller purges
        // without ever writing into the real dir.
        (\App\StaticCache\Cache::configured($this->app))
            ->purgeStory($slug, $cats, $seriesSlugs, $authorSlug, [], $challengeSlugs);
    }

    private function notifyPublish(string $slug): void
    {
        (new \App\PublishFanout($this->db, $this->mailer,
            rtrim((string) $this->app->config('base_url', 'http://localhost:8080'), '/')))->publish($slug);
    }

    private function wordLimitError(string $content): ?string { return \App\ChapterChecks::wordLimitError($content); }

    private function canonicalPublishAt(string $raw): ?string { return \App\ChapterChecks::canonicalPublishAt($raw); }

    /** @return array{string,string,string,string,string} */
    private function chapterInput(): array
    {
        $p = $this->request->post;
        return [trim((string) ($p['title'] ?? '')), (string) ($p['content'] ?? ''),
            trim((string) ($p['notes_before'] ?? '')), trim((string) ($p['notes_after'] ?? '')),
            trim((string) ($p['publish_at'] ?? ''))];
    }
}
