<?php $this->layout('layout'); ?>
<section class="auth-card">
  <h1><?= \App\Lang::t('profilefields.delete_heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('profilefields.delete_lede', ['label' => $this->e($field['label'])]) ?></p>
  <form method="post" action="/profilefields/destroy/<?= (int) $field['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('profilefields.delete_confirm') ?></button>
    <a href="/profilefields"><?= \App\Lang::t('profilefields.keep') ?></a>
  </form>
</section>
