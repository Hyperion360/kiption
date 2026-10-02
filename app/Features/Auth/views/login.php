<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('auth.login.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('auth.login.lede') ?></p>
<?php if ($error): ?><p id="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<?php if (!empty($verified)): ?><p class="notice"><?= \App\Lang::t('auth.login.email_verified') ?></p><?php endif; ?>
<form method="post" action="/auth/attempt">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label><?= \App\Lang::t('auth.email') ?> <input type="email" name="email" required autocomplete="email"<?= isset($error) ? ' aria-describedby="form-error"' : '' ?>></label>
  <label><?= \App\Lang::t('auth.password') ?> <input type="password" name="password" required autocomplete="current-password"<?= isset($error) ? ' aria-describedby="form-error"' : '' ?>></label>
  <button><?= \App\Lang::t('auth.login.heading') ?></button>
</form>
<p class="auth-links"><a href="/auth/forgot"><?= \App\Lang::t('auth.login.forgot') ?></a></p>
</section>
