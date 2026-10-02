<?php // app/src/Viewer.php
namespace App;
use Kip\{Auth, Database, Session};
use Kip\Http\Request;

/** Who is viewing a public page, or 0 for a guest. Kip checks the password
 *  epoch only on #[Auth] routes, so a public page that reads user_id straight
 *  from the session keeps serving a session revoked by a password change.
 *  Pages that show member-private rows (notes, bookmarks, reading progress)
 *  resolve the viewer here instead. The cookie gate keeps the cookieless,
 *  cacheable path from starting a session; the epoch SELECT is the
 *  auth-validation query the per-page budget already exempts. */
final class Viewer
{
    public static function id(Request $request, Session $session, Database $db): int
    {
        $me = $request->cookies !== [] ? (int) ($session->get('user_id') ?? 0) : 0;
        if ($me !== 0 && !(new Auth($db, $session))->sessionValid()) return 0;
        return $me;
    }
}
