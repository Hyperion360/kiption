<?php // app/views/menu_post.php
/* "Post a story" in the site menu. Signed-in block (no vars): shown by role,
   which SessionGuard sets only when this request validated a signed-in
   session; non-admins lose it while posting is paused. Guest block
   (guest => true): the same link for everyone, built only from cached
   settings (posting open, registration not closed), so the anonymous page
   cache can hold it. The form itself still sends a guest to sign in. */
if (!empty($guest)) {
    if (!\App\Posting::guestOffered()) return;
} else {
    $menuRole = \App\SessionGuard::role();
    if ($menuRole === '') return;
    if ($menuRole !== 'admin' && !\App\Settings::on('submissions_open')) return;
} ?>
      <a href="/story/new" class="menu-post"><?= \App\Lang::t('nav.post_story') ?></a>
