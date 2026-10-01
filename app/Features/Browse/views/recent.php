<?php // app/views/browse/recent.php ?>
<?php // C10 (frame M6): story cards (serif title, strong byline, meta with
     // the status badge, two-line-clamped summary) plus, on the recent
     // screen only, the filter chips and the member's Continue pill. The
     // category listing shares the card list without chips or filter. Every
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
<ul class="story-list">
<?php foreach ($stories as $s): ?>
  <li class="story-card">
    <a class="story-title" href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <p class="byline"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($s['penname']) ?></strong></p>
    <p class="meta"><?= $this->e($s['rating_label']) ?> ·
      <?php if ($s['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?> ·
      <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?> ·
      <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $s['updated_at'], 0, 10))]) ?></p>
    <p class="summary clamp-2"><?= $this->e($s['summary']) ?></p>
    <?php if (($s['last_position'] ?? null) !== null): ?>
    <a class="continue-pill" href="/story/read/<?= $this->e($s['slug']) ?>/<?= (int) $s['last_position'] ?>"><?= \App\Lang::t('browse.continue_pill', ['roman' => \App\Features\Reader\Roman::numeral((int) $s['last_position']), 'pct' => (int) $s['read_pct']]) ?></a>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
<?php if ($stories === []): ?>
  <li class="meta"><?= \App\Lang::t('story.no_stories') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page - 1 ?>"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?><?= $pagerPrefix ?><?= $page + 1 ?>"><?= \App\Lang::t('common.older') ?></a>
