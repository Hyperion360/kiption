<?php // app/views/admin_tabs.php
/* Phones and tablets: a bar fixed to the bottom edge with the four places an
   owner goes most, plus Menu, which opens the full list above. Plain links,
   so it works with scripting off. Hidden once the sidebar has room. */
$icons = [
    'home' => '<path d="M4 11 12 4l8 7v9h-5v-6H9v6H4z"/>',
    'queue' => '<path d="M4 6h16v9H4z"/><path d="M4 15v4h16v-4"/><path d="m9 10 2 2 4-4"/>',
    'members' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.900 1.900 6.500 5.500"/><path d="M16 4.800a3.500 3.500 0 0 1 0 6.400M18 14.800c2 .700 3.200 2.500 3.500 5.200"/>',
    'settings' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
]; ?>
<nav class="admin-tabs" aria-label="<?= $this->e(\App\Lang::t('manage.tabs_label')) ?>">
  <?php foreach ($admin['tabs'] as $t): ?>
  <a href="<?= $this->e($t['url']) ?>"<?= $t['current'] ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><?= $icons[$t['icon']] ?></svg><span><?= \App\Lang::t($t['label']) ?></span><?php if ($t['count'] > 0): ?><span class="tab-count"><span class="visually-hidden"><?= $this->e(\App\Lang::t('manage.waiting_inside')) ?> </span><?= (int) $t['count'] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <a href="#admin-nav"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><?= $icons['menu'] ?></svg><span><?= \App\Lang::t('manage.tab_menu') ?></span></a>
</nav>
