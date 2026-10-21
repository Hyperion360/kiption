<?php // app/views/compare/index.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= \App\Lang::t('compare.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('compare.lead') ?></p>
  <ul>
<?php foreach ($rows as $row): ?>
    <li><a href="<?= $this->e($row['path']) ?>"><?= $this->e($row['title']) ?></a></li>
<?php endforeach; ?>
  </ul>
</article>
