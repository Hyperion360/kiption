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
     $pagerPrefix = $activeFilter !== '' ? '?filter=' . $this->e($activeFilter) . '&amp;page=' : '?page=';
     $chipSet = [['', 'browse.all'], ['complete', 'story.complete'], ['wip', 'browse.filter_wip'], ['under10k', 'browse.filter_under10k']]; ?>
<?php if (!empty($feedHref)): ?>
<link rel="alternate" type="application/atom+xml" title="<?= $this->e($title ?? \App\Lang::t('common.feed_title')) ?>" href="<?= $this->e($feedHref) ?>">
<?php endif; ?>
<h1><?= $this->e($title ?? \App\Lang::t('browse.recent_heading')) ?></h1>
<?php if (!empty($chips)): ?>
<nav class="filter-chips" aria-label="<?= $this->e(\App\Lang::t('browse.filter_aria')) ?>">
<?php foreach ($chipSet as [$value, $key]): ?>
  <a class="chip<?= $activeFilter === $value ? ' is-active' : '' ?>" href="<?= $this->e($baseUrl) ?><?= $value !== '' ? '?filter=' . $value : '' ?>"<?= $activeFilter === $value ? ' aria-current="true"' : '' ?>><?= \App\Lang::t($key) ?></a>
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
  <li class="meta"><?= \App\Lang::t('story.no_stories') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page - 1 ?>"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page + 1 ?>"><?= \App\Lang::t('common.older') ?></a>
