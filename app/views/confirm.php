<?php // app/views/confirm.php ?>
<?php $this->layout('layout'); ?>
<section class="confirm-step">
  <h1><?= \App\Lang::t('admin.confirm_heading') ?></h1>
  <p class="lede"><?= \App\Lang::t($question) ?></p>
  <p class="chapter-meta"><?= \App\Lang::t('admin.confirm_note') ?></p>
  <form method="post" action="<?= $this->e($path) ?>" class="confirm-actions">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <input type="hidden" name="confirmed" value="1">
    <?php foreach ($carry as $name => $value) {
        echo '<input type="hidden" name="' . $this->e($name) . '" value="' . $this->e($value) . '">';
    } ?>
    <a class="btn" href="<?= $this->e($back) ?>"><?= \App\Lang::t('admin.confirm_cancel') ?></a>
    <button type="submit" class="btn-danger"><?= \App\Lang::t($yes) ?></button>
  </form>
</section>
