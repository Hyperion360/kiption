<?php // app/views/challenges/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('challenges.index') ?></h1>
  <?php if ($loggedIn): ?><p><a href="/challenges/new"><?= \App\Lang::t('challenges.new') ?></a></p><?php endif; ?>
  <?php if ($rows === []): ?>
    <p class="chapter-meta"><?= \App\Lang::t('challenges.empty_index') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <a href="/challenges/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a>
        <span class="chapter-meta">
          | <?= \App\Lang::t('common.works_count', ['n' => number_format((int) $r['item_count'])]) ?>
          | <?= \App\Lang::t('challenges.m_' . $r['membership']) ?>
          | <?= $this->e(substr((string) $r['created_at'], 0, 10)) ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
