<?php // app/views/favorites/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('favorites.heading') ?></h1>
  <?php if ($rows === []): ?><p class="chapter-meta"><?= \App\Lang::t('favorites.none') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <a href="/story/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a>
        <span class="chapter-meta">(<?= \App\Lang::t('favorites.kudos_updated', ['n' => number_format((int) $r['kudos_count']), 'date' => $this->e($r['updated_at'])]) ?>)</span>
        <p><?= $this->e($r['summary']) ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
