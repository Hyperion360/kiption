<?php // app/views/mailusers/preview.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('mailusers.preview_heading') ?></h1>
  <p><strong><?= \App\Lang::t('mailusers.recipient_count', ['n' => number_format($count)]) ?></strong></p>
  <p><?= \App\Lang::t('mailusers.subject') ?>: <strong><?= $this->e($subject) ?></strong></p>
  <pre class="mail-preview"><?= $this->e($body) ?></pre>
  <form method="post" class="form-card" action="/mailusers/send">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <input type="hidden" name="subject" value="<?= $this->e($subject) ?>">
    <input type="hidden" name="body" value="<?= $this->e($body) ?>">
    <button type="submit"><?= \App\Lang::t('mailusers.send') ?></button>
    <a href="/mailusers"><?= \App\Lang::t('mailusers.edit_again') ?></a>
  </form>
</section>
