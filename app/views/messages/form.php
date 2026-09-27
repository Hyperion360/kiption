<?php // app/views/messages/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('messages.compose', ['name' => $this->e($partner['penname'])]) ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p class="chapter-meta"><?= \App\Lang::t('messages.help') ?></p>
  <form method="post" action="/messages/send/<?= $this->e($slug) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('messages.body_label') ?> <textarea name="body" rows="8" maxlength="5000" required></textarea></label>
    <button type="submit"><?= \App\Lang::t('messages.send') ?></button>
  </form>
</section>
