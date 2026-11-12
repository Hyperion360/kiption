<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('signupcheck.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('signupcheck.lede') ?></p>
  <?php if ($saved): ?><p class="notice" role="status"><?= \App\Lang::t('signupcheck.saved') ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><p class="error" role="alert"><?= \App\Lang::t($error) ?></p><?php endif; ?>
  <form method="post" action="/signupcheck/save">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><input type="checkbox" name="enabled" value="1"<?= (int) $values['enabled'] === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('signupcheck.enabled') ?></label>
    <label><?= \App\Lang::t('signupcheck.question') ?> <input name="question" maxlength="200" value="<?= $this->e($values['question']) ?>"></label>
    <label><?= \App\Lang::t('signupcheck.answer') ?> <input name="answer" maxlength="100" value="<?= $this->e($values['answer']) ?>"></label>
    <button type="submit"><?= \App\Lang::t('signupcheck.save') ?></button>
  </form>
</section>
