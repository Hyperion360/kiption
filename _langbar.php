<?php // app/Features/Text/views/_langbar.php ?>
<nav class="chapter-meta" aria-label="<?= $this->e(\App\Lang::t('text.language')) ?>">
  <?= \App\Lang::t('text.language') ?>:
  <?php foreach ($languages as $l): ?>
  <?php if ($l['code'] === $lang): ?><strong><?= $this->e($l['name']) ?></strong><?php else: ?><a href="<?= $this->e($base) ?>?lang=<?= $this->e($l['code']) ?>"><?= $this->e($l['name']) ?></a><?php endif; ?>
  <?php endforeach; ?>
</nav>
