<?php // app/views/site_browse.php
/* The Browse panel under the header (desktop). Fixed links come from the
   same flags as the rest of the nav; categories with counts and popular tags
   come from the BrowsePanel file, so this renders with no query and, when the
   file is missing, still offers the ways in plus All Categories. Takes navOn
   (NavLinks::builtIns) and navFile. */
$panel = \App\BrowsePanel::read(\App\BrowsePanel::fileFor($navFile ?? ''));
$ways = [['/browse/recent', 'nav.way_recent', 'nav.way_recent_note', $navOn['/browse/recent']],
         ['/browse/recent?filter=complete', 'nav.way_complete', 'nav.way_complete_note', true],
         ['/top', 'top.heading', 'nav.way_top_note', \App\Features::on('toplists')],
         ['/series', 'nav.series', 'nav.way_series_note', $navOn['/series'] && \App\Settings::on('series_on')],
         ['/lists', 'foot.lists', 'nav.way_lists_note', \App\Features::on('lists')],
         ['/challenges', 'challenges.index', 'nav.way_challenges_note', \App\Features::on('challenges')]];
?>
<div class="browse-panel" role="region" aria-label="<?= $this->e(\App\Lang::t('nav.browse')) ?>">
  <div class="bp-grid">
    <section class="bp-cats">
      <h2 class="bp-label"><?= \App\Lang::t('nav.panel_categories') ?></h2>
<?php if ($panel['categories'] !== []): ?>
      <ul class="bp-catlist">
<?php foreach (array_slice($panel['categories'], 0, 10) as $c): ?>
        <li><a href="/browse/category/<?= $this->e($c['slug']) ?>"><span><?= $this->e($c['name']) ?></span><span class="bp-n"><?= number_format($c['count']) ?></span></a></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
      <a class="bp-all" href="/browse"><?= \App\Lang::t('nav.all_categories') ?></a>
    </section>
    <section class="bp-ways">
      <h2 class="bp-label"><?= \App\Lang::t('nav.panel_ways') ?></h2>
      <ul class="bp-waylist">
<?php foreach ($ways as [$wayUrl, $wayKey, $noteKey, $wayOn]): if (!$wayOn) continue; ?>
        <li><a href="<?= $wayUrl ?>"><strong><?= \App\Lang::t($wayKey) ?></strong><span><?= \App\Lang::t($noteKey) ?></span></a></li>
<?php endforeach; ?>
      </ul>
    </section>
    <section class="bp-tags">
      <h2 class="bp-label"><?= \App\Lang::t('nav.panel_tags') ?></h2>
<?php if ($panel['tags'] !== []): ?>
      <ul class="bp-chips">
<?php foreach ($panel['tags'] as $t): ?>
        <li><a href="/browse/filter?tag[]=<?= (int) $t['id'] ?>"><?= $this->e($t['name']) ?> <span><?= number_format($t['count']) ?></span></a></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
      <a class="bp-all" href="/browse/filter"><?= \App\Lang::t('nav.filter_tags') ?></a>
      <p class="bp-start"><strong><?= \App\Lang::t('nav.start_heading') ?></strong><a href="/browse/filter?status=complete&amp;wmax=20000"><?= \App\Lang::t('nav.start_story') ?></a></p>
    </section>
  </div>
</div>
