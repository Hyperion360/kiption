<?php $this->layout('layout'); ?>
<section class="auth-card">
<h1><?= \App\Lang::t('auth.link.magic_heading') ?></h1>
<p class="lede"><?= \App\Lang::t('auth.link.magic_lede') ?></p>
<form method="post" action="/auth/enter">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="t" value="<?= $this->e($token) ?>">
  <button><?= \App\Lang::t('auth.link.magic_submit') ?></button>
</form>
</section>
