<?php // app/views/admin_nav.php
/* The list of every owner screen, grouped, with the current one marked.
   One block of links serves both shapes: a sidebar that stays in view on
   wide screens, and a sheet the Menu tab opens on phones (the :target
   idiom, so it works with scripting off). Each group is a details element:
   only the one holding the current screen starts open, and a folded group
   shows how many things wait inside it. Every link is in the page either way. */ ?>
<nav id="admin-nav" class="admin-nav" aria-label="<?= $this->e(\App\Lang::t('manage.nav_label')) ?>">
  <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a>
  <a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
  <?php /* Shown by the adminnav module only: without scripting the full list is already on screen. */ ?>
  <input class="admin-nav-filter" type="search" hidden data-js-module="adminnav" aria-keyshortcuts="/ Control+K Meta+K" data-hint="<?= $this->e(\App\Lang::t('manage.find_key')) ?>" aria-label="<?= $this->e(\App\Lang::t('manage.find')) ?>" placeholder="<?= $this->e(\App\Lang::t('manage.find')) ?>">
  <a class="admin-nav-home" href="/manage"<?= $admin['current'] === '/manage' ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('manage.heading') ?></a>
  <?php foreach ($admin['groups'] as $i => $g): ?>
  <details class="admin-nav-section"<?= $g['open'] ? ' open' : '' ?>>
    <summary class="admin-nav-group" id="admin-nav-g<?= $i ?>"><span><?= \App\Lang::t($g['label']) ?></span><?php if ($g['badge'] > 0): ?><span class="admin-nav-badge" title="<?= $this->e(\App\Lang::t('manage.waiting_inside')) ?>"><?= (int) $g['badge'] ?></span><?php endif; ?></summary>
    <ul aria-labelledby="admin-nav-g<?= $i ?>">
      <?php foreach ($g['links'] as $l): ?>
      <li><a href="<?= $this->e($l['url']) ?>"<?= $l['current'] ? ' aria-current="page"' : '' ?>><?= \App\Lang::t($l['label']) ?><?php if ($l['count'] > 0): ?><span class="admin-nav-count"><?= (int) $l['count'] ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endforeach; ?>
  <p class="admin-nav-none" role="status" hidden><?= \App\Lang::t('manage.find_none') ?></p>
</nav>
