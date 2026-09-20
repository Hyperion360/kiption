<?php // app/views/news/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('news.heading') ?></h1>
<ul class="story-list">
<?php foreach ($items as $n): ?>
  <li>
    <a href="/news/view/<?= (int) $n['id'] ?>"><?= $this->e($n['title']) ?></a>
    <div class="meta">
      <?= number_format((int) $n['comment_count']) ?> <?= \App\Lang::t((int) $n['comment_count'] === 1 ? 'news.comment' : 'news.comments') ?>
      | <?= \App\Lang::t('news.posted', ['date' => $this->e(substr($n['published_at'], 0, 10))]) ?>
    </div>
<?php endforeach; ?>
<?php if ($items === []): ?>
  <li class="meta"><?= \App\Lang::t('news.none') ?></li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?>?page=<?= $page - 1 ?>"><?= \App\Lang::t('common.newer') ?></a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?>?page=<?= $page + 1 ?>"><?= \App\Lang::t('common.older') ?></a>
