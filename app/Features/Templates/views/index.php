<?php // app/views/templates/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('templates.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('templates.help') ?></p>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <strong><?= $this->e($r['name']) ?></strong>
        <span class="chapter-meta"><?= $this->e($r['subject']) ?></span>
        <a href="/templates/edit/<?= $this->e($r['name']) ?>"><?= \App\Lang::t('common.edit') ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
