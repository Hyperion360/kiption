<?php // app/Features/Manage/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('manage.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('manage.intro') ?></p>
<?php foreach ($groupNames as $gid => $gkey): if (empty($groups[$gid])) continue; ?>
<section aria-labelledby="manage-<?= $this->e($gid) ?>">
  <h2 id="manage-<?= $this->e($gid) ?>"><?= \App\Lang::t($gkey) ?></h2>
  <ul>
    <?php foreach ($groups[$gid] as $l): ?>
    <li><a href="<?= $this->e($l['url']) ?>"><?= \App\Lang::t($l['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php if ($database): ?>
<section aria-labelledby="manage-advanced">
  <h2 id="manage-advanced"><?= \App\Lang::t('manage.group_advanced') ?></h2>
  <p><a href="/admin"><?= \App\Lang::t('manage.database') ?></a></p>
  <p class="chapter-meta"><?= \App\Lang::t('manage.database_warning') ?></p>
</section>
<?php endif; ?>
