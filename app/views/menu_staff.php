<?php // app/views/menu_staff.php
/* The staff group of the site menu: Archive admin for admins, Queue for
   moderators and admins. Same SessionGuard rule as menu_post: the group shows
   only when this request validated a signed-in session with that role.
   Takes nothing. */
$menuRole = \App\SessionGuard::role();
if ($menuRole !== 'admin' && $menuRole !== 'moderator') return; ?>
      <span class="sheet-group"><?= \App\Lang::t('nav.operator') ?></span>
<?php if ($menuRole === 'admin'): ?>
      <a href="/manage"><?= \App\Lang::t('nav.manage') ?></a>
<?php endif; ?>
      <a href="/queue"><?= \App\Lang::t('nav.queue') ?></a>
