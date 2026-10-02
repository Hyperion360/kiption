<?php // app/views/browse/recent.php ?>
<?php // C10 (frame M6): story cards (serif title, strong byline, meta with
     // the status badge, two-line-clamped summary) plus, on the recent
     // screen only, the filter chips and the member's Continue pill. The
     // category listing shares the card list without chips or filter. The
     // card loop itself is the _story_cards partial, shared byte-for-byte
     // with the ?fragment=1 infinite-scroll renders. Every
     // <li> closes; the pill markup exists only when the viewer's
     // reading_history row does, so guest bytes stay reader-neutral.
     $this->layout('layout');
     $activeFilter = (string) ($filter ?? '');
     $activeCat = (string) ($cat ?? '');
     // Pager and All-chip URLs: the pager keeps BOTH facets (cat and
     // filter); the All chip clears both, the one way back to the whole
     // list (every filter chip sets a filter). Chips never carry
     // ?page= (a facet click resets to page 1); only the pager does.
     $keep = [];
     if ($activeCat !== '') $keep[] = 'cat=' . rawurlencode($activeCat);
     if ($activeFilter !== '') $keep[] = 'filter=' . rawurlencode($activeFilter);
     $pagerPrefix = $keep === [] ? '?page=' : '?' . implode('&amp;', $keep) . '&amp;page=';
     $allHref = $this->e($baseUrl);
     $allActive = $activeCat === '' && $activeFilter === '';
     // The category chips come from the stories already on the page: the
     // cats_blob fold decoded once here, distinct slug=>name pairs (the
     // (story_id, category_id) PK deduped the pairs per story; the same
     // category across many stories collapses in this loop).
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
<?php // The infinite-scroll contract (Task 6): the module hook rides the
     // card list, and data-next-url is the OLDER page's URL (recent pages
     // are newest-first, so page 2 is older), empty once a page comes back
     // short of a full page (the JS stop signal). data-canonical is this
     // page's canonical path. ?>
<?php $nextUrl = !empty($hasOlder) ? $pagerPrefix . ($page + 1) : ''; ?>
<ul class="story-list" data-js-module="infinite" data-next-url="<?= $nextUrl !== '' ? $this->e($baseUrl) . $nextUrl : '' ?>" data-canonical="<?= $this->e($path ?? '') ?>">
<?= $this->render('browse/_story_cards', ['stories' => $stories]) ?>
<?php if ($stories === []): ?>
  <?php // A bound cat that matched nothing speaks for itself; every other
        // empty listing keeps the generic line. ?>
  <li class="meta"><?= \App\Lang::t(!empty($chips) && $activeCat !== '' ? 'browse.no_cat_stories' : 'story.no_stories') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1 || !empty($hasOlder)): /* no Older link past the last full page */ ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<?php if (!empty($hasOlder)): ?><a class="pager-next" href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.older') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
