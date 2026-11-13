<?php // app/views/admin_nav.php
/* The list of every owner screen, grouped, with the current one marked.
   One block of links serves both shapes: a sidebar that stays in view on
   wide screens, and a sheet the Menu tab opens on phones (the :target
   idiom, so it works with scripting off). */ ?>
<nav id="admin-nav" class="admin-nav" aria-label="<?= $this->e(\App\Lang::t('manage.nav_label')) ?>">
  <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a>
  <a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
  <a class="admin-nav-home" href="/manage"<?= $admin['current'] === '/manage' ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('manage.heading') ?></a>
  <?php /* Shown by the adminnav module only: without scripting the full list is already on screen. */ ?>
  <input class="admin-nav-filter" type="search" hidden data-js-module="adminnav" aria-label="<?= $this->e(\App\Lang::t('manage.find')) ?>" placeholder="<?= $this->e(\App\Lang::t('manage.find')) ?>">
  <?php foreach ($admin['groups'] as $i => $g): ?>
  <p class="admin-nav-group" id="admin-nav-g<?= $i ?>"><?= \App\Lang::t($g['label']) ?></p>
  <ul aria-labelledby="admin-nav-g<?= $i ?>">
    <?php foreach ($g['links'] as $l): ?>
    <li><a href="<?= $this->e($l['url']) ?>"<?= $l['current'] ? ' aria-current="page"' : '' ?>><?= \App\Lang::t($l['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
  <p class="admin-nav-none" hidden><?= \App\Lang::t('manage.find_none') ?></p>
</nav>
