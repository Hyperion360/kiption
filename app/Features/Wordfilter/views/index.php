<?php // app/Features/Wordfilter/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('wordfilter.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('wordfilter.intro') ?></p>
<?php if ($error): ?><p class="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/wordfilter/save" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label><?= \App\Lang::t('wordfilter.label') ?>
    <textarea name="words" rows="20" class="plain-list" spellcheck="false"><?= $this->e(implode("\n", $words)) ?></textarea>
  </label>
  <p class="chapter-meta"><?= $this->e(\App\Lang::t('wordfilter.count', ['count' => count($words)])) ?></p>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('wordfilter.save') ?></button>
</form>
<section class="try-box" id="try">
  <h2><?= \App\Lang::t('wordfilter.try_heading') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('wordfilter.try_help') ?></p>
  <form method="post" action="/wordfilter/try#try" class="form-card">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('wordfilter.try_label') ?>
      <input type="text" name="sentence" maxlength="500" value="<?= $this->e((string) (($tried ?? null)['sentence'] ?? '')) ?>">
    </label>
    <button type="submit"><?= \App\Lang::t('wordfilter.try_button') ?></button>
  </form>
  <?php if (($tried ?? null) !== null): ?>
  <div class="facts-now" role="status">
    <?php if ($tried['word'] === null): ?>
    <p><?= \App\Lang::t('wordfilter.try_clean') ?></p>
    <?php else: ?>
    <p><?= \App\Lang::t('wordfilter.try_blocked', ['word' => $this->e($tried['word'])]) ?></p>
    <p><?= \App\Lang::t('wordfilter.try_member_sees') ?></p>
    <blockquote><?= $this->e(\App\Lang::t('wordfilter.blocked', ['word' => $tried['word']])) ?></blockquote>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
