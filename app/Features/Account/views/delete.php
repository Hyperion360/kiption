<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('account.delete_heading') ?></h1>
<?php if (!empty($error)): ?><p class="error" role="alert"><?= \App\Lang::t($error) ?></p><?php endif; ?>
<?php if ($lastAdmin): ?>
<p class="lede"><?= \App\Lang::t('account.err_last_admin') ?></p>
<p><a href="/account/settings"><?= \App\Lang::t('account.delete_back') ?></a></p>
<?php else: ?>
<p class="lede"><?= \App\Lang::t('account.delete_lede') ?></p>
<form method="post" action="/account/destroy">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <fieldset>
    <legend><?= \App\Lang::t('account.delete_stories_legend') ?></legend>
    <label><input type="radio" name="stories" value="keep" checked> <?= \App\Lang::t('account.delete_keep') ?></label>
    <label><input type="radio" name="stories" value="remove"> <?= \App\Lang::t('account.delete_remove') ?></label>
  </fieldset>
  <label><?= \App\Lang::t('account.current_password') ?> <input type="password" name="current_password" required autocomplete="current-password"></label>
  <button type="submit"><?= \App\Lang::t('account.delete_submit') ?></button>
  <a href="/account/settings"><?= \App\Lang::t('account.delete_back') ?></a>
</form>
<?php endif; ?>
</section>
