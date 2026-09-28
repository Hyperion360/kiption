<?php // app/views/lists/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('lists.index') ?></h1>
  <p><a href="/lists/new"><?= \App\Lang::t('lists.new') ?></a></p>
  <?php if ($rows === []): ?>
    <p class="chapter-meta"><?= \App\Lang::t('lists.empty_index') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <a href="/lists/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a>
        <span class="chapter-meta">
          | <?= \App\Lang::t('common.works_count', ['n' => number_format((int) $r['item_count'])]) ?>
          <?php if ((int) $r['is_public'] !== 1): ?>| <?= \App\Lang::t('lists.private_label') ?><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
