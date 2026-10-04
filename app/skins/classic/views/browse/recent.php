<?php // app/skins/classic/views/browse/recent.php ?>
<?php // The classic skin's story index (recent and category listings): the
     // same chips, pager, and infinite-scroll contract as the app default,
     // with the card list wrapped in the skin's dense listing container
     // (classic.css tightens the card rhythm through .skin-listing) and the
     // heading on one compact line. The ?fragment=1 partial is NOT overridden:
     // page and fragment keep sharing the default card bytes, which is what
     // the infinite module appends.
     $this->layout('layout');
     $activeFilter = (string) ($filter ?? '');
     $activeCat = (string) ($cat ?? '');
     $keep = [];
     if ($activeCat !== '') $keep[] = 'cat=' . rawurlencode($activeCat);
     if ($activeFilter !== '') $keep[] = 'filter=' . rawurlencode($activeFilter);
     $pagerPrefix = $keep === [] ? '?page=' : '?' . implode('&amp;', $keep) . '&amp;page=';
     $allHref = $this->e($baseUrl);
     $allActive = $activeCat === '' && $activeFilter === '';
     $catChips = [];
     foreach ($stories as $s) {
         $cats = json_decode((string) ($s['cats_blob'] ?? ''), true);
         foreach (is_array($cats) ? $cats : [] as $c) {
             if (is_array($c) && isset($c['slug'], $c['name']) && !isset($catChips[(string) $c['slug']])) {
                 $catChips[(string) $c['slug']] = (string) $c['name'];
             }
         }
     }
     $chipSet = [['complete', 'story.complete'], ['wip', 'browse.filter_wip'], ['under10k', 'browse.filter_under10k']]; ?>
<?php if (!empty($feedHref)): ?>
<link rel="alternate" type="application/atom+xml" title="<?= $this->e($title ?? \App\Lang::t('common.feed_title')) ?>" href="<?= $this->e($feedHref) ?>">
<?php endif; ?>
<div class="page skin-listing">
<h1><?= $this->e($title ?? \App\Lang::t('browse.recent_heading')) ?></h1>
<?php if (!empty($chips)): ?>
<nav class="filter-chips" aria-label="<?= $this->e(\App\Lang::t('browse.filter_aria')) ?>">
  <a class="chip<?= $allActive ? ' is-active' : '' ?>" href="<?= $allHref ?>"<?= $allActive ? ' aria-current="true"' : '' ?>><?= \App\Lang::t('browse.all') ?></a>
<?php foreach ($chipSet as [$value, $key]): ?>
  <a class="chip<?= $activeFilter === $value ? ' is-active' : '' ?>" href="<?= $this->e($baseUrl) ?><?= $value !== '' ? '?filter=' . $value : '' ?>"<?= $activeFilter === $value ? ' aria-current="true"' : '' ?>><?= \App\Lang::t($key) ?></a>
<?php endforeach; ?>
<?php foreach ($catChips as $chipSlug => $chipName): ?>
  <a class="chip<?= $activeCat === $chipSlug ? ' is-active' : '' ?>" href="<?= $this->e($baseUrl) ?>?cat=<?= rawurlencode($chipSlug) ?><?= $activeFilter !== '' ? '&amp;filter=' . rawurlencode($activeFilter) : '' ?>"<?= $activeCat === $chipSlug ? ' aria-current="true"' : '' ?>><?= $this->e($chipName) ?></a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
<?php $nextUrl = !empty($hasOlder) ? $pagerPrefix . ($page + 1) : ''; ?>
<ul class="story-list" data-js-module="infinite" data-next-url="<?= $nextUrl !== '' ? $this->e($baseUrl) . $nextUrl : '' ?>" data-canonical="<?= $this->e($path ?? '') ?>">
<?= $this->render('browse/_story_cards', ['stories' => $stories]) ?>
<?php if ($stories === []): ?>
  <li class="meta"><?= \App\Lang::t(!empty($chips) && $activeCat !== '' ? 'browse.no_cat_stories' : 'story.no_stories') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1 || !empty($hasOlder)): ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<?php if (!empty($hasOlder)): ?><a class="pager-next" href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.older') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
</div>
