<?php // app/views/auth/register.php ?>
<?php $this->layout('layout'); ?>
<section class="auth-card">
  <h1><?= \App\Lang::t('auth.register.heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/auth/store">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('auth.register.penname') ?> <input name="penname" required maxlength="30" value="<?= $this->e($penname ?? '') ?>"></label>
    <label><?= \App\Lang::t('auth.email') ?> <input type="email" name="email" required value="<?= $this->e($email ?? '') ?>"></label>
    <label><?= \App\Lang::t('auth.password') ?> <input type="password" name="password" required minlength="8"></label>
    <label><?= \App\Lang::t('auth.register.confirm_password') ?> <input type="password" name="confirm" required minlength="8"></label>
    <?php if (($mode ?? '') === 'invite'): ?>
      <label><?= \App\Lang::t('auth.register.invite_code') ?> <input name="invite" required value="<?= $this->e($invite ?? '') ?>"></label>
    <?php endif; ?>
    <button type="submit"><?= \App\Lang::t('auth.register.submit') ?></button>
  </form>
  <?php if (!empty($terms_url)): ?>
  <p class="auth-terms"><?php if (\App\FooterLegal::linkable($terms_url)): ?><?= \App\Lang::t('auth.terms_pre') ?> <a href="<?= $this->e($terms_url) ?>"><?= \App\Lang::t('auth.terms_label') ?></a>.<?php else: ?><?= \App\Lang::t('auth.terms_link', ['terms' => \App\Lang::t('auth.terms_label')]) ?><?php endif; ?></p>
  <?php endif; ?>
  <p class="auth-links"><span><?= \App\Lang::t('auth.register.have_account') ?> <a href="/auth/login"><?= \App\Lang::t('nav.login') ?></a>.</span></p>
</section>
