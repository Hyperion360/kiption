<?php // app/views/top/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('top.heading') ?></h1>
<?php
// Five sections in a fixed order. Rows arrive rank-ordered per section (the
// fold's ORDER BY k, rank); rank renumbers gated survivors contiguously, so
// the <ol> numbering IS the displayed rank. Empty sections render their
// honest empty line; Top rated's floor of 3 root ratings makes "Not enough
// ratings yet" its only empty state. Trending is last and batch-stale: reads
// land via the beacon, which never purges this page, so the section carries
// its staleness label and only refreshes on engagement writes and rebuilds.
?>
<section>
  <h2><?= \App\Lang::t('top.favorited') ?></h2>
<?php if ($sections['favorites'] === []): ?>
  <p class="meta"><?= \App\Lang::t('top.no_favorites') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($sections['favorites'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= \App\Lang::t('top.favorites_count', ['n' => (int) $row['count']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2><?= \App\Lang::t('top.kudos') ?></h2>
<?php if ($sections['kudos'] === []): ?>
  <p class="meta"><?= \App\Lang::t('top.no_kudos') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($sections['kudos'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= \App\Lang::t('top.kudos_count', ['n' => (int) $row['count']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2><?= \App\Lang::t('top.reviewed') ?></h2>
<?php if ($sections['reviews'] === []): ?>
  <p class="meta"><?= \App\Lang::t('top.no_reviews') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($sections['reviews'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= \App\Lang::t('top.reviews_count', ['n' => (int) $row['count']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2><?= \App\Lang::t('top.rated') ?></h2>
<?php if ($sections['rated'] === []): ?>
  <p class="meta"><?= \App\Lang::t('top.not_enough') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($sections['rated'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= \App\Lang::t('top.average_from', ['average' => $this->e($row['average']), 'n' => (int) $row['ratings']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2><?= \App\Lang::t('top.trending') ?></h2>
  <p class="meta"><?= \App\Lang::t('top.trending_stale') ?></p>
<?php if ($sections['trending'] === []): ?>
  <p class="meta"><?= \App\Lang::t('top.no_trending') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($sections['trending'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= \App\Lang::t('top.trending_count', ['n' => (int) $row['count']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
