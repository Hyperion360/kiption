<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('auth.forgot.heading') ?></h1>
<?php if ($sent): ?>
  <p><?= \App\Lang::t('auth.forgot.sent') ?></p>
<?php else: ?>
  <form method="post" action="/auth/remind">
    <label><?= \App\Lang::t('auth.email') ?> <input type="email" name="email" required autocomplete="email"></label>
    <button><?= \App\Lang::t('auth.forgot.send') ?></button>
  </form>
<?php endif; ?>
