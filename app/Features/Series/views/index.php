<?php // app/Features/Series/views/index.php ?>
<?php // The /series index: one story-card-family card per series (title link,
     // owner byline, works count) and the recent screen's pager shape (Newer
     // only from page 2, Older always, ?page= links). No chips, no infinite
     // scroll: the listing is one query and one list (YAGNI). ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title ?? \App\Lang::t('series.index_heading')) ?></h1>
<ul class="story-list">
<?php foreach ($series as $s): ?>
  <li class="story-card">
    <a class="story-title" href="/series/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <p class="byline"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($s['penname']) ?></strong></p>
    <p class="meta"><?= \App\Lang::t('common.works_count', ['n' => number_format((int) $s['story_count'])]) ?></p>
  </li>
<?php endforeach; ?>
<?php if ($series === []): ?>
  <li class="meta"><?= \App\Lang::t('series.none_yet') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?>?page=<?= $page - 1 ?>"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?>?page=<?= $page + 1 ?>"><?= \App\Lang::t('common.older') ?></a>
