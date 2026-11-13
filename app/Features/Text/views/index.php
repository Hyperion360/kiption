<?php // app/Features/Text/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('text.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('text.intro') ?></p>
<?= $this->render('text/_langbar', ['languages' => $languages, 'lang' => $lang, 'base' => '/text']) ?>
<form method="get" action="/text/search" class="form-card" role="search">
  <input type="hidden" name="lang" value="<?= $this->e($lang) ?>">
  <label><?= \App\Lang::t('text.search_label') ?>
    <input name="q" required maxlength="100">
  </label>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('text.search_button') ?></button>
</form>
<h2><?= \App\Lang::t('text.groups') ?></h2>
<?php /* Shown by the listfilter module only. */ ?>
<p class="live-filter" hidden data-js-module="listfilter" data-filter-rows=".text-groups > li">
  <label><?= \App\Lang::t('admin.filter_label') ?> <input type="search" autocomplete="off"></label>
  <span class="chapter-meta" data-filter-none hidden><?= \App\Lang::t('admin.filter_none') ?></span>
</p>
<?php foreach ($sections as $section => $groups): ?>
<h3><?= \App\Lang::t('text.section.' . $section) ?></h3>
<ul class="manage-list text-groups">
  <?php foreach ($groups as $code => $g): ?>
  <li><a href="<?= $this->e('/text/group/' . $code . '?lang=' . $lang) ?>"><span class="manage-name"><?= $this->e($g['label']) ?></span>
    <span class="manage-what"><?= $this->e(\App\Lang::t('text.group_count', ['changed' => $g['changed'], 'total' => $g['total']])) ?></span></a></li>
  <?php endforeach; ?>
</ul>
<?php endforeach; ?>
<p><a class="btn" href="/text/languages"><?= \App\Lang::t('text.manage_languages') ?></a></p>
<h2><?= \App\Lang::t('text.export') ?></h2>
<p class="chapter-meta"><?= \App\Lang::t('text.export_note') ?></p>
<p><a class="btn" href="<?= $this->e('/text/export?lang=' . $lang) ?>"><?= \App\Lang::t('text.export') ?></a></p>
<h2><?= \App\Lang::t('text.import') ?></h2>
<p class="chapter-meta"><?= \App\Lang::t('text.import_note') ?></p>
<form method="post" action="/text/import" enctype="multipart/form-data" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="lang" value="<?= $this->e($lang) ?>">
  <label><?= \App\Lang::t('text.import_file_label') ?> <input type="file" name="file" accept="application/json,.json" required></label>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('text.import_button') ?></button>
</form>
