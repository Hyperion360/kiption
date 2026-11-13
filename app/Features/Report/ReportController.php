<?php // app/Features/Report/ReportController.php
namespace App\Features\Report;
use Kip\{App, Database, Http\Request, Http\Response, Session, View};
use Kip\Routing\{Auth as AuthAttr, Post}; // the aliased Auth import ships FROM BIRTH: without it #[AuthAttr] resolves to a nonexistent class, PHP never validates attributes, and the member gate silently disables
use App\Adminness;
use App\Repositories\ReportRepository;

final class ReportController
{
    public function __construct(
        private Database $db, private Request $request, private Session $session,
        private View $view, private App $app, private \App\MailGuard $mailer,
    ) {}

    #[AuthAttr] #[Post]
    public function story(string $slug): Response
    {
        $userId = $this->session->get('user_id');
        [$result] = (new ReportRepository($this->db))->reportStory(
            $slug, is_int($userId) ? $userId : null, $this->request->postStr('reason'), $this->request->ip);
        if ($result === true) \App\ActivityLog::by($this->db, $this->session, 'reports.file_story', $slug);
        return $this->outcome($result, $slug);
    }

    #[AuthAttr] #[Post]
    public function review(string $id): Response
    {
        $userId = $this->session->get('user_id');
        [$result] = (new ReportRepository($this->db))->reportReview(
            (int) $id, is_int($userId) ? $userId : null, $this->request->postStr('reason'));
        if ($result !== true) return $this->outcome($result, '');
        \App\ActivityLog::by($this->db, $this->session, 'reports.file_review', 'review ' . (int) $id);
        $slug = (string) ($this->db->one(
            'SELECT s.slug FROM reviews r JOIN stories s ON s.id = r.story_id WHERE r.id = ?', [(int) $id])['slug'] ?? '');
        return $this->outcome($result, $slug);
    }

    #[AuthAttr] #[Post]
    public function resolve(string $id, string $op): Response
    {
        if (Adminness::requireModerator($this->db, $this->session) instanceof Response) {
            return new Response(\App\Lang::t('error.forbidden'), 403);
        }
        if ($op === 'dismiss') {
            (new ReportRepository($this->db))->resolve((int) $id);
            \App\ActivityLog::by($this->db, $this->session, 'reports.dismiss', 'report ' . (int) $id);
        }
        return Response::redirect('/queue');
    }

    /** The confirm step for removing reported content: shows what was
     *  reported and asks for an optional reason to send the author. */
    #[AuthAttr]
    public function remove(string $id): Response|string
    {
        $me = Adminness::requireModerator($this->db, $this->session);
        if ($me instanceof Response) {
            return new Response(\App\Lang::t('error.forbidden'), 403);
        }
        $target = $this->target($id);
        if ($target === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $title = \App\Lang::t('report.remove_heading');
        return $this->view->render('report/remove', [
            'title' => $title,
            'head' => $this->head()->withTitle($title)->withNoindex(),
            'theme' => \App\Theme::current($this->request),
            'request' => $this->request,
            'navFile' => (string) $this->app->config('nav_file', ''),
            'path' => $this->request->path,
            'target' => $target, 'reportId' => (int) $id,
            'isAdmin' => ($me['role'] ?? '') === 'admin', // an admin keeps the admin list; a moderator never sees it
            'csrf' => $this->session->csrfToken(), 'loggedIn' => true,
        ]);
    }

    /** Take the reported content down, tell its author why, close the report.
     *  A story is soft-deleted; a review has no such column, so it is deleted
     *  (its reports go with it). A guest review has no address to mail. */
    #[AuthAttr] #[Post]
    public function takedown(string $id): Response
    {
        if (Adminness::requireModerator($this->db, $this->session) instanceof Response) {
            return new Response(\App\Lang::t('error.forbidden'), 403);
        }
        $target = $this->target($id);
        if ($target === null) return new Response(\App\Lang::t('error.not_found'), 404);
        $cache = \App\StaticCache\Cache::configured($this->app);
        if ($target['kind'] === 'story') {
            $repo = new \App\Repositories\AuthoringRepository($this->db);
            $cats = $repo->categorySlugs((int) $target['story_id']);
            $this->db->query('UPDATE stories SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL', [date('c'), (int) $target['story_id']]);
            [$seriesSlugs, $authorSlug, $challengeSlugs] = $repo->purgeData((int) $target['story_id'], (int) $target['author_id']);
            $listSlugs = (new \App\Repositories\ListsRepository($this->db))->publicListSlugsForStory((int) $target['story_id']);
            $cache->purgeStory((string) $target['slug'], $cats, $seriesSlugs, $authorSlug, $listSlugs, $challengeSlugs);
            $cache->purgeAuthors();
            $this->db->query('UPDATE reports SET resolved_at = ? WHERE story_id = ? AND resolved_at IS NULL', [date('c'), (int) $target['story_id']]);
        } else {
            $this->db->query('DELETE FROM reviews WHERE id = ?', [(int) $target['review_id']]);
            $cache->purgeStory((string) $target['slug'], []);
        }
        \App\ActivityLog::by($this->db, $this->session, 'reports.takedown', $target['kind'] . ' "' . $target['title'] . '" (report ' . (int) $id . ')');
        if ($target['author_email'] !== null && !\App\AccountSecurity::isReservedEmail((string) $target['author_email'])) {
            [$subject, $body] = \App\Templates::get($this->db, 'content_removed', ...\App\Templates::pair('content_removed'));
            $reason = trim($this->request->postStr('reason'));
            $pairs = [
                '{what}' => \App\Lang::t($target['kind'] === 'story' ? 'report.what_story' : 'report.what_review'),
                '{title}' => (string) $target['title'],
                '{reason}' => $reason !== '' ? $reason : \App\Lang::t('report.default_reason'),
            ];
            try {
                $this->mailer->send((string) $target['author_email'], strtr($subject, $pairs), strtr($body, $pairs));
            } catch (\Throwable $e) {
                error_log('content removed mail failed: ' . $e->getMessage());
            }
        }
        return Response::redirect('/queue');
    }

    /** The open report's target, with its author's address; null when the
     *  report is closed, unknown, or its content is already gone. */
    private function target(string $id): ?array
    {
        if (preg_match('#^[1-9][0-9]{0,9}$#', $id) !== 1) return null;
        $row = $this->db->one(
            'SELECT rp.story_id, rp.review_id FROM reports rp WHERE rp.id = ? AND rp.resolved_at IS NULL', [(int) $id]);
        if ($row === null) return null;
        if ($row['story_id'] !== null) {
            $t = $this->db->one(
                'SELECT s.id story_id, s.slug, s.title, s.author_id, u.email author_email, u.penname
                 FROM stories s JOIN users u ON u.id = s.author_id WHERE s.id = ? AND s.deleted_at IS NULL', [(int) $row['story_id']]);
            return $t === null ? null : $t + ['kind' => 'story', 'excerpt' => ''];
        }
        $t = $this->db->one(
            "SELECT r.id review_id, r.body excerpt, s.slug, s.title, u.email author_email, u.penname
             FROM reviews r JOIN stories s ON s.id = r.story_id LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?", [(int) $row['review_id']]);
        return $t === null ? null : $t + ['kind' => 'review'];
    }

    private function head(): \App\Seo\Head
    {
        return \App\Seo\Head::make(
            siteName: (string) $this->app->config('site_name', 'Kiption'),
            ogImage: (string) $this->app->config('og_image', ''),
            baseUrl: rtrim((string) $this->app->config('base_url', ''), '/'),
        );
    }

    private function outcome(bool|string $result, string $slug): Response
    {
        if ($result === 'notfound') return new Response(\App\Lang::t('error.not_found'), 404);
        if ($result === 'empty') return new Response(\App\Lang::t('report.err_reason'), 422);
        if ($result === 'duplicate') return new Response(\App\Lang::t('report.err_duplicate'), 409);
        return Response::redirect('/story/view/' . $slug);
    }
}
