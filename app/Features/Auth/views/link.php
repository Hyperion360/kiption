<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('auth.link.heading') ?></h1>
<?php if ($state === 'sent'): ?>
  <p class="notice" role="status"><?= \App\Lang::t('auth.link.sent') ?></p>
<?php else: ?>
  <?php if ($state === 'expired'): ?><p class="notice" role="alert"><?= \App\Lang::t('auth.link.expired') ?></p><?php endif; ?>
  <p class="lede"><?= \App\Lang::t('auth.link.lede') ?></p>
  <form method="post" action="/auth/send">
    <label><?= \App\Lang::t('auth.email') ?> <input type="email" name="email" required autocomplete="email"></label>
    <button><?= \App\Lang::t('auth.link.send') ?></button>
  </form>
<?php endif; ?>
<p class="auth-links"><a href="/auth/login"><?= \App\Lang::t('auth.back_to_login') ?></a></p>
</section>
