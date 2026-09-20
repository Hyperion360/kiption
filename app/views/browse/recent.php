<?php // app/views/browse/recent.php ?>
<?php $this->layout('layout'); ?>
<?php if (!empty($feedHref)): ?>
<link rel="alternate" type="application/atom+xml" title="<?= $this->e($title ?? \App\Lang::t('common.feed_title')) ?>" href="<?= $this->e($feedHref) ?>">
<?php endif; ?>
<h1><?= $this->e($title ?? \App\Lang::t('browse.recent_heading')) ?></h1>
<ul class="story-list">
<?php foreach ($stories as $s): ?>
  <li>
    <a href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <?= \App\Lang::t('story.by') ?> <?= $this->e($s['penname']) ?>
    <div class="meta">
      <?= $this->e($s['rating_label']) ?>
      <?php if ($s['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?>
      <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?>
      <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $s['updated_at'], 0, 10))]) ?>
    </div>
    <p><?= $this->e($s['summary']) ?></p>
<?php endforeach; ?>
<?php if ($stories === []): ?>
  <li class="meta"><?= \App\Lang::t('story.no_stories') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?>?page=<?= $page - 1 ?>"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?>?page=<?= $page + 1 ?>"><?= \App\Lang::t('common.older') ?></a>
