<?php // app/Features/Text/views/languages.php ?>
<?php $this->layout('layout'); ?>
<p><a href="/text?lang=<?= $this->e($lang) ?>"><?= \App\Lang::t('text.back') ?></a></p>
<h1><?= \App\Lang::t('text.manage_languages') ?></h1>
<ul>
  <?php foreach ($languages as $l): ?>
  <li><?= $this->e($l['name']) ?> <span class="chapter-meta">(<?= $this->e($l['code']) ?>, <?= \App\Lang::t($l['dir'] === 'rtl' ? 'text.rtl' : 'text.ltr') ?><?= $l['owned'] ? '' : ', ' . \App\Lang::t('text.builtin') ?><?= $l['code'] === $default ? ', ' . \App\Lang::t('text.default_mark') : '' ?>)</span>
    <a href="/text?lang=<?= $this->e($l['code']) ?>"><?= \App\Lang::t('text.edit_language') ?></a>
    <?php if ($l['owned'] && $l['code'] !== $default): ?>
    <form method="post" action="/text/removelanguage" class="inline-form">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="code" value="<?= $this->e($l['code']) ?>">
      <button type="submit" class="link-btn"><?= \App\Lang::t('text.remove') ?></button>
    </form>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
<h2><?= \App\Lang::t('text.add_language') ?></h2>
<form method="post" action="/text/addlanguage" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label><?= \App\Lang::t('text.code') ?>
    <input name="code" required pattern="[a-z]{2,3}" maxlength="3">
  </label>
  <label><?= \App\Lang::t('text.name') ?>
    <input name="name" required maxlength="40">
  </label>
  <label><?= \App\Lang::t('text.direction') ?>
    <select name="dir">
      <option value="ltr"><?= \App\Lang::t('text.ltr') ?></option>
      <option value="rtl"><?= \App\Lang::t('text.rtl') ?></option>
    </select>
  </label>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('text.add') ?></button>
</form>
