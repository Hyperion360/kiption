<?php // app/Features/Wordfilter/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('wordfilter.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('wordfilter.intro') ?></p>
<?php if ($error): ?><p class="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/wordfilter/save" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label><?= \App\Lang::t('wordfilter.label') ?>
    <textarea name="words" rows="20"><?= $this->e(implode("\n", $words)) ?></textarea>
  </label>
  <p class="chapter-meta"><?= $this->e(\App\Lang::t('wordfilter.count', ['count' => count($words)])) ?></p>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('wordfilter.save') ?></button>
</form>
