<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('contact.heading') ?></h1>
<?php if ($sent): ?>
<p class="notice" role="status"><?= \App\Lang::t('contact.sent') ?></p>
<?php else: ?>
<p class="lede"><?= \App\Lang::t('contact.lede') ?></p>
<?php if (!empty($error)): ?><p class="error" role="alert"><?= \App\Lang::t($error) ?></p><?php endif; ?>
<form method="post" action="/contact/send">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <div hidden aria-hidden="true"><label>Leave this empty <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label></div>
  <label><?= \App\Lang::t('contact.name') ?> <input type="text" name="name" required maxlength="100" value="<?= $this->e($old['name'] ?? '') ?>"></label>
  <label><?= \App\Lang::t('contact.email') ?> <input type="email" name="email" required maxlength="254" value="<?= $this->e($old['email'] ?? '') ?>"></label>
  <label><?= \App\Lang::t('contact.message') ?> <textarea name="message" required maxlength="5000" rows="8"><?= $this->e($old['message'] ?? '') ?></textarea></label>
  <button type="submit"><?= \App\Lang::t('contact.send') ?></button>
</form>
<?php endif; ?>
</section>
