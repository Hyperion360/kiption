<?php // app/views/menu_post.php
/* "Post a story", the first link of the signed-in block of the site menu. The
   role comes from SessionGuard, which is '' unless this request validated a
   signed-in session, so a cookieless (cacheable) render never shows it.
   Non-admins do not see it while posting is paused (a cached setting, no
   query; admins are never paused, per Posting::paused). Takes nothing. */
$menuRole = \App\SessionGuard::role();
if ($menuRole === '') return;
if ($menuRole !== 'admin' && !\App\Settings::on('submissions_open')) return; ?>
      <a href="/story/new" class="menu-post"><?= \App\Lang::t('nav.post_story') ?></a>
