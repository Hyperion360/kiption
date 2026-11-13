<?php // app/Features/Manage/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('manage.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('manage.intro') ?></p>
<?php foreach ($groupNames as $gid => $gkey): if (empty($groups[$gid])) continue; ?>
<section aria-labelledby="manage-<?= $this->e($gid) ?>">
  <h2 id="manage-<?= $this->e($gid) ?>"><?= \App\Lang::t($gkey) ?></h2>
  <ul class="manage-list">
    <?php foreach ($groups[$gid] as $l): ?>
    <li><a href="<?= $this->e($l['url']) ?>"><span class="manage-name"><?= \App\Lang::t($l['label']) ?></span> <span class="manage-what"><?= \App\Lang::t($l['label'] . '_what') ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php if ($database): ?>
<section aria-labelledby="manage-advanced">
  <h2 id="manage-advanced"><?= \App\Lang::t('manage.group_advanced') ?></h2>
  <ul class="manage-list">
    <li><a href="/admin"><span class="manage-name"><?= \App\Lang::t('manage.database') ?></span> <span class="manage-what"><?= \App\Lang::t('manage.database_warning') ?></span></a></li>
  </ul>
</section>
<?php endif; ?>
