<?php // app/views/news/index.php ?>
<?php // Site news (undesigned in the comps): page head, posts as story-card
     // rows (serif title, meta), the shared pager. ?>
<?php $this->layout('layout'); ?>
<div class="page">
<header class="page-head">
  <h1><?= \App\Lang::t('news.heading') ?></h1>
</header>
<?php if ($items === []): ?>
<p class="empty-state"><?= \App\Lang::t('news.none') ?></p>
<?php else: ?>
<ul class="story-list">
<?php foreach ($items as $n): ?>
  <li class="story-card">
    <a class="story-title" href="/news/view/<?= (int) $n['id'] ?>"><?= $this->e($n['title']) ?></a>
    <p class="meta"><?= \App\Lang::t('news.posted', ['date' => $this->e(substr($n['published_at'], 0, 10))]) ?> · <?= number_format((int) $n['comment_count']) ?> <?= \App\Lang::t((int) $n['comment_count'] === 1 ? 'news.comment' : 'news.comments') ?></p>
  </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($page > 1 || !empty($hasOlder)): ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?>?page=<?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<?php if (!empty($hasOlder)): ?><a class="pager-next" href="<?= $this->e($baseUrl) ?>?page=<?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.older') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
</div>
