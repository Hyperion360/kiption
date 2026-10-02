<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('auth.reset.heading') ?></h1>
<?php if ($error !== null): ?><p id="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/auth/confirm">
  <input type="hidden" name="token" value="<?= $this->e($token) ?>">
  <label><?= \App\Lang::t('auth.reset.new_password') ?> <input type="password" name="password" minlength="8" required autocomplete="new-password"<?= $error !== null ? ' aria-describedby="form-error"' : '' ?>></label>
  <button><?= \App\Lang::t('auth.reset.submit') ?></button>
</form>
</section>
