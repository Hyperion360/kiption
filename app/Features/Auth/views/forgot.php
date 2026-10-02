<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('auth.forgot.heading') ?></h1>
<?php if ($sent): ?>
  <p class="notice"><?= \App\Lang::t('auth.forgot.sent') ?></p>
<?php else: ?>
  <p class="lede"><?= \App\Lang::t('auth.forgot.lede') ?></p>
  <form method="post" action="/auth/remind">
    <label><?= \App\Lang::t('auth.email') ?> <input type="email" name="email" required autocomplete="email"></label>
    <button><?= \App\Lang::t('auth.forgot.send') ?></button>
  </form>
<?php endif; ?>
<p class="auth-links"><a href="/auth/login"><?= \App\Lang::t('auth.back_to_login') ?></a></p>
</section>
