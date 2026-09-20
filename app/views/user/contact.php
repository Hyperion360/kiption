<?php // app/views/user/contact.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('user.contact_heading', ['name' => $this->e($target['penname'])]) ?></h1>
  <?php if ($sent): ?>
    <p class="chapter-meta"><?= \App\Lang::t('user.contact_sent', ['name' => $this->e($target['penname'])]) ?></p>
    <p><a href="/user/view/<?= $this->e($slug) ?>"><?= \App\Lang::t('user.contact_back') ?></a></p>
  <?php else: ?>
    <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
    <p class="chapter-meta"><?= \App\Lang::t('user.contact_help') ?></p>
    <form method="post" action="/user/contact/<?= $this->e($slug) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('user.contact_message') ?> <textarea name="body" rows="8" maxlength="5000" required></textarea></label>
      <button type="submit"><?= \App\Lang::t('user.contact_send') ?></button>
    </form>
  <?php endif; ?>
</section>
