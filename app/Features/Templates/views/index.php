<?php // app/views/templates/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('templates.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('templates.help') ?></p>
  <ul class="admin-rows">
    <?php foreach ($rows as $r): ?>
      <li>
        <strong><?= $this->e(ucfirst(str_replace('_', ' ', (string) $r['name']))) ?></strong>
        <span class="chapter-meta"><?= $this->e($r['subject']) ?></span>
        <div class="queue-actions"><a class="btn" href="/templates/edit/<?= $this->e($r['name']) ?>"><?= \App\Lang::t('common.edit') ?></a></div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
