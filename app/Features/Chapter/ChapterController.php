<?php // app/Features/Chapter/ChapterController.php
namespace App\Features\Chapter;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post};
use App\Repositories\AuthoringRepository;

final class ChapterController
{
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
        if (\App\Posting::paused($this->db, $this->uid())) return \App\Posting::stopPage($this->view, $this->request, $this->app);
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        if (trim($content) === '') {
            return $this->form($slug, null, \App\Lang::t('chapter.err_text'));
        }
        if (($wordError = $this->wordLimitError($content)) !== null) {
            return $this->form($slug, null, $wordError);
        }
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return $this->form($slug, null, \App\Lang::t('chapter.publish_invalid'));
        }
        $auto = $this->autoValidates();
        // The round-robin expansion reaches chapter CREATION only (finding 7's
        // scope ruling): a full member may open the gate on rr stories, while
        // update/delete and every story form stay story-side.
        $rr = \App\Features::on('roundrobin');
        // The post-password field (Fictioneer parity): hashed here, stored by
        // the repository; a new chapter was never public, so no purge rider.
        $accessPassword = trim((string) ($this->request->post['access_password'] ?? ''));
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->createChapter($slug, $this->uid(), $title, $content, $before, $after, $auto, $publishAt, $rr,
                $accessPassword === '' ? null : password_hash($accessPassword, PASSWORD_DEFAULT));
        } catch (\RuntimeException) {
            return new Response(\App\Lang::t('error.not_found'), 404); // non-owned or unknown story, same contract as story writes
        }
        $this->purge($slug, $cats, $seriesSlugs, $authorSlug, $challengeSlugs);
        \App\ActivityLog::record($this->db, $this->uid(), 'chapter.create', $slug . ': ' . $title);
        // Finding 5: a scheduled create never fans out. storyForNotify only
        // checks live_chapters >= 1, so scheduling onto a live story would
        // notify followers about a chapter they cannot read yet.
        if ($auto && $publishAt === null) $this->notifyPublish($slug);
        // A pure rr contributor cannot see /story/edit (owner-gated by
        // construction); ownStory WITHOUT the flag is exactly the edit page's
        // gate, so the probe cannot drift from the surface it routes to.
        try {
            $this->repo()->ownStory($slug, $this->uid());
            $land = '/story/edit/';
        } catch (\RuntimeException) {
            $land = '/story/view/';
        }
        return Response::redirect($land . $slug);
    }

    #[AuthAttr] #[Post]
    public function update(string $slug, int $position): Response|string
    {
        if ($this->formRow($slug, $position) === null) return new Response(\App\Lang::t('error.not_found'), 404);
        [$title, $content, $before, $after, $rawPublishAt] = $this->chapterInput();
        if (trim($content) === '') {
            return $this->form($slug, $position, \App\Lang::t('chapter.err_text'));
        }
        if (($wordError = $this->wordLimitError($content)) !== null) {
            return $this->form($slug, $position, $wordError);
        }
        try {
            $publishAt = $this->canonicalPublishAt($rawPublishAt);
        } catch (\InvalidArgumentException) {
            return $this->form($slug, $position, \App\Lang::t('chapter.publish_invalid'));
        }
        $revision = $this->request->post['accept_revision'] ?? ($this->request->post['base_revision'] ?? null);
        if (!is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            return $this->form($slug, $position, \App\Lang::t('chapter.reload_before_save'), 409);
        }
        // The post-password pair (Fictioneer parity; the repository hashes the
        // raw input and applies the clear-wins trio). A NULL-to-set lock on a
        // live chapter is the delete path's forever-stale case: the pre-write
        // hash decides the deferred re-purge rider below.
        $accessPassword = trim((string) ($this->request->post['access_password'] ?? ''));
        $accessClear = ($this->request->post['access_clear'] ?? '') === '1';
        $locking = !$accessClear && $accessPassword !== '';
        $old = $this->formRow($slug, $position);
        $wasOpen = ($old['access_hash'] ?? null) === null || (string) $old['access_hash'] === '';
        try {
            [$cats, $seriesSlugs, $authorSlug, $challengeSlugs] = $this->repo()->updateChapter($slug, $position, $this->uid(), $title, $content, $before, $after, $publishAt, $accessPassword, $accessClear, $revision);
        } catch (\App\ChapterChanged $e) {
            $key = $slug . ':' . $position;
            $this->formRows[$key] = array_replace($this->formRows[$key], $e->savedFields());
            $this->currentRevisions[$key] = $e->savedRevision();
            return $this->form($slug, $position, \App\Lang::t('chapter.changed'), 409);
        } catch (\PDOException $e) {
            $native = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($native & 0xff, [5, 6], true)) throw $e;
            return $this->form($slug, $position, \App\Lang::t('chapter.busy'), 503, ['Retry-After' => '1']);
        } catch (\RuntimeException) {
            return new Response(\App\Lang::t('error.not_found'), 404);
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
        if ($wasLive === 1) $this->notifyPublish($slug); // only an already-live chapter's edit is "news"
        return \App\Saved::to('/chapter/edit/' . $slug . '/' . $position);
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
        }
        $writerServer = [];
        foreach (['title', 'content', 'notes_before', 'notes_after', 'publish_at'] as $field) {
            $writerServer[$field] = (string) ($baseRow[$field] ?? '');
        }
        $writerServer['publish_at'] = substr($writerServer['publish_at'], 0, 16);
        $writerLabels = [];
        foreach (['words', 'selection', 'unsaved', 'saved', 'saving', 'focus', 'typewriter', 'compare', 'archive', 'recovery',
            'enable', 'disable', 'retry', 'restore', 'replaceUnsaved', 'useDraft', 'discard', 'copy',
            'recoveryUnavailable', 'recoveryProtected', 'recoveryOff', 'recoveryTab', 'recoveryOffer',
            'recoveryConflict', 'recoveryDiscarded', 'recoveryRestored', 'recoveryWritten',
            'recoveryClearFailed', 'recoveryRemoveFailed', 'recoveryProtectedRetained', 'copyFallback', 'copied'] as $label) {
            $writerLabels[$label] = \App\Lang::t('writer.' . $label);
        }
        $html = $this->view->render('chapter/form', [
            'title' => $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit_title'),
            'head' => $this->head()->withTitle($position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit_title'))
                ->withCanonical($this->request->path)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'story' => $row,
            'baseRow' => $baseRow,
            'baseRevision' => $baseRevision,
            'currentRevision' => $currentRevision,
            'writerOwner' => $this->uid(),
            'writerChapter' => $position === null ? 'new:' . $baseRow['story_id'] : 'chapter:' . $baseRow['chapter_id'],
            'writerProtected' => (int) ($baseRow['recovery_protected'] ?? 0) === 1
                || ($error !== null && is_string($this->request->post['access_password'] ?? null)
                    && $this->request->post['access_password'] !== ''),
            'writerServer' => $writerServer,
            'writerCheckUrl' => $position === null ? '/chapter/new/' . $slug : '/chapter/edit/' . $slug . '/' . $position,
            'conflict' => isset($this->currentRevisions[$key]),
            'position' => $position,
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'writerLabels' => $writerLabels,
            'notice' => $error === null && \App\Saved::message($this->request) === 'admin.saved_saved'
                ? \App\Lang::t('chapter.saved') : '',
            'loggedIn' => true,
        ]);
        return $error === null ? $html : new Response($html, $status, $headers);
    }

    /** The owner's per-chapter word limits (0 means none): a friendly message with the count, or null when fine. */
    private function wordLimitError(string $content): ?string
    {
        $min = (int) \App\Settings::get('min_words');
        $max = (int) \App\Settings::get('max_words');
        if ($min === 0 && $max === 0) return null;
        $n = \App\Markdown::wordCount($content);
        if ($min > 0 && $n < $min) return \App\Lang::t('chapter.too_short', ['n' => number_format($n), 'min' => number_format($min)]);
        if ($max > 0 && $n > $max) return \App\Lang::t('chapter.too_long', ['n' => number_format($n), 'max' => number_format($max)]);
        return null;
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

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    /** @return array{string,string,string,string,string} */
    private function chapterInput(): array
    {
        $p = $this->request->post;
        return [trim((string) ($p['title'] ?? '')), (string) ($p['content'] ?? ''),
            trim((string) ($p['notes_before'] ?? '')), trim((string) ($p['notes_after'] ?? '')),
            trim((string) ($p['publish_at'] ?? ''))];
    }

    /** The schedule input's normalizer (finding 1). The zone is OPTIONAL in
     *  the accepted shape because the form's datetime-local input submits
     *  without one; a zone-less value reads as UTC (the third createFromFormat
     *  argument pins that, whatever the host default timezone is). Every
     *  accepted value is canonicalized to Y-m-d\TH:i:s\Z UTC so releaseDue's
     *  lexicographic publish_at <= now compares exact instants: raw offsets
     *  compare wrong in both directions. Empty returns null (the immediate
     *  path unchanged); junk throws for the 422. */
    private function canonicalPublishAt(string $raw): ?string
    {
        if ($raw === '') return null;
        if (!preg_match('#^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$#', $raw)) {
            throw new \InvalidArgumentException('publish_at');
        }
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:iP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw, $utc);
            $errs = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
                return $dt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
            }
        }
        throw new \InvalidArgumentException('publish_at'); // shape matched, instant did not (2099-13-45T99:99)
    }
}
