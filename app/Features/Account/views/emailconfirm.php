<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('account.email_confirm_heading') ?></h1>
<?php if ($token !== null): ?>
<p class="lede"><?= \App\Lang::t('account.email_confirm_lede') ?></p>
<form method="post" action="/account/emailapply">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="t" value="<?= $this->e($token) ?>">
  <button><?= \App\Lang::t('account.email_confirm_submit') ?></button>
</form>
<?php else: ?>
<p class="lede"><?= \App\Lang::t($message ?? 'account.email_link_expired') ?></p>
<?php endif; ?>
</section>
