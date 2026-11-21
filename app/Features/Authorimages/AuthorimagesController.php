<?php // app/Features/Authorimages/AuthorimagesController.php
namespace App\Features\Authorimages;
use Kip\{App, Database, Http\Request, Http\Response, Session, Storage, UploadException};
use Kip\Routing\{Auth as AuthAttr, Post};

/** The chapter editor's image upload. Each author has a personal library
 *  (author_images, owner_id from the session, never from the request). The
 *  administrator's chapter_images_on switch and the existing uploads_on switch
 *  gate new uploads; the existing member storage share, extension allowlist,
 *  finfo MIME check and 2 MiB limit stay authoritative through
 *  StorageQuota::putUnderCap. A magic-byte check runs first so a file never
 *  reaches storage on a claimed type alone. Every reply is JSON. The class
 *  name has no inner capital for the same reason as Adminmembers. */
final class AuthorimagesController
{
    public const LIBRARY_MAX = 100;

    public function __construct(
        private Database $db, private Request $request, private Session $session,
        private App $app, private Storage $storage,
    ) {}

    #[AuthAttr] #[Post]
    public function upload(): Response
    {
        $uid = (int) $this->session->get('user_id');
        $user = $this->db->one('SELECT approved_at, email_verified_at, is_locked FROM users WHERE id = ?', [$uid]);
        if ($user === null || $user['approved_at'] === null || $user['email_verified_at'] === null || (int) $user['is_locked'] === 1) {
            return $this->json(['error' => \App\Lang::t('authorimages.forbidden')], 403);
        }
        $file = $this->request->file('file');
        if ($file === null) return $this->json(['error' => \App\Lang::t('images.err_upload')], 422);
        $key = $this->request->post['upload_key'] ?? null;
        if ($key !== null && (!is_string($key) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key) !== 1)) {
            return $this->json(['error' => \App\Lang::t('images.err_upload')], 422);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $digest = is_file($tmp) ? (string) hash_file('sha256', $tmp) : '';
        // A retry after a lost response resolves before any setting, throttle
        // or cap check: an upload that already happened stays acknowledgeable.
        if ($key !== null) {
            $had = $this->db->one('SELECT id, path, content_sha256 FROM author_images WHERE owner_id = ? AND upload_key = ?', [$uid, $key]);
            if ($had !== null) {
                return hash_equals((string) $had['content_sha256'], $digest)
                    ? $this->json(['id' => (int) $had['id'], 'src' => (string) $had['path']])
                    : $this->json(['error' => \App\Lang::t('authorimages.conflict')], 409);
            }
        }
        if (!\App\Settings::on('chapter_images_on') || !\App\Settings::on('uploads_on')) {
            return $this->json(['error' => \App\Lang::t('authorimages.off')], 403);
        }
        if (!(new \App\AttemptLimiter($this->db))->claim('author_image', (string) $uid, $this->request->ip, 30, 60)) {
            return $this->json(['error' => \App\Lang::t('authorimages.throttled')], 429);
        }
        if ($this->count($uid) >= self::LIBRARY_MAX) return $this->json(['error' => \App\Lang::t('authorimages.limit')], 422);
        if (!self::hasImageMagic($tmp)) return $this->json(['error' => \App\Lang::t('authorimages.err_type')], 422);
        $cfg = (array) $this->app->config('uploads');
        $storedCap = (int) ($cfg['max_bytes'] ?? 2097152);
        // Big phone and Word images may arrive up to the intake cap and are shrunk to the stored cap below.
        if ((int) filesize($tmp) > (int) ($cfg['intake_max_bytes'] ?? 10485760)) return $this->json(['error' => \App\Lang::t('authorimages.too_big')], 422);
        $intake = (new \App\AuthorImageIntake($storedCap))->prepare($tmp, (string) ($file['name'] ?? 'image'));
        if ($intake->refusalKey !== null) return $this->json(['error' => \App\Lang::t($intake->refusalKey)], 422);
        $processed = $intake->file !== $tmp;
        $file['tmp_name'] = $intake->file;
        $file['name'] = $intake->name;
        $file['size'] = (int) filesize($intake->file);
        // A re-encoded file is not an HTTP upload, so move_uploaded_file would refuse it: rename it into place instead.
        $store = $processed
            ? new Storage((string) ($cfg['dir'] ?? ''), $storedCap, (array) ($cfg['ext'] ?? []), static fn(string $a, string $b): bool => rename($a, $b))
            : $this->storage;
        $quota = \App\StorageQuota::forApp($this->app);
        try {
            $path = $quota->putUnderCap($file, false, $store);
        } catch (UploadException) {
            if ($processed) @unlink($intake->file);
            return $this->json(['error' => \App\Lang::t('images.err_upload')], 422);
        } catch (\Throwable $e) {
            if ($processed) @unlink($intake->file);
            throw $e;
        }
        if ($processed && $path === null) @unlink($intake->file);
        if ($path === null) return $this->json(['error' => \App\Lang::t('images.storage_full')], 422);
        try {
            $this->db->begin();
            if ($this->count($uid) >= self::LIBRARY_MAX) throw new \LengthException('full');
            $this->db->query('INSERT INTO author_images (owner_id, path, content_sha256, upload_key, created_at) VALUES (?, ?, ?, ?, ?)',
                [$uid, $path, $digest, $key, date('c')]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable) {}
            // Not in the catalog yet: retires the file and refunds the bytes. removeFiles swallows its own failures, so the real reply is never hidden.
            $quota->removeFiles([basename($path)]);
            if ($key !== null && !($e instanceof \LengthException)) {
                $won = $this->db->one('SELECT id, path, content_sha256 FROM author_images WHERE owner_id = ? AND upload_key = ?', [$uid, $key]);
                if ($won !== null && hash_equals((string) $won['content_sha256'], $digest)) {
                    return $this->json(['id' => (int) $won['id'], 'src' => (string) $won['path']]);
                }
            }
            return $this->json(['error' => \App\Lang::t($e instanceof \LengthException ? 'authorimages.limit' : 'images.err_upload')], 422);
        }
        return $this->json(['id' => $id, 'src' => $path]);
    }

    /** Remove one image from the signed-in author's own library. Another
     *  author's id is a plain 404, so the reply never says it exists. An image
     *  any chapter still shows is refused with the story's title (409); a file
     *  an avatar or cover also uses keeps its bytes (removeFiles checks). */
    #[AuthAttr] #[Post]
    public function delete(): Response
    {
        $uid = (int) $this->session->get('user_id');
        $id = (int) ($this->request->post['id'] ?? 0);
        $row = $id > 0 ? $this->db->one('SELECT id, path FROM author_images WHERE id = ? AND owner_id = ?', [$id, $uid]) : null;
        if ($row === null) return $this->json(['error' => \App\Lang::t('error.not_found')], 404);
        $use = \App\ChapterImageUse::chaptersUsing($this->db, (string) $row['path']);
        if ($use !== []) {
            return $this->json(['error' => \App\Lang::t('authorimages.err_in_use', ['stories' => implode(', ', array_values(array_unique(array_column($use, 'story'))))])], 409);
        }
        $this->db->query('DELETE FROM author_images WHERE id = ? AND owner_id = ?', [$id, $uid]);
        \App\StorageQuota::forApp($this->app)->removeFiles([basename((string) $row['path'])]);
        return $this->json(['deleted' => $id]);
    }

    /** PNG, JPEG, GIF87a/89a and RIFF....WEBP signatures. */
    public static function hasImageMagic(string $file): bool
    {
        $head = is_file($file) ? (string) file_get_contents($file, false, null, 0, 12) : '';
        return str_starts_with($head, "\x89PNG\r\n\x1a\n")
            || str_starts_with($head, "\xff\xd8\xff")
            || str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')
            || (strlen($head) === 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP');
    }

    private function count(int $uid): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM author_images WHERE owner_id = ?', [$uid])['n'] ?? 0);
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response(json_encode($body, JSON_THROW_ON_ERROR), $status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
