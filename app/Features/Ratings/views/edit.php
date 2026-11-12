<?php // app/Features/Ratings/views/edit.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('ratings.edit_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/ratings/update/<?= (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('ratings.label') ?> <input name="label" required maxlength="40" value="<?= $this->e($row['label']) ?>"></label>
    <label class="inline"><input type="checkbox" name="is_adult" value="1"<?= (int) $row['is_adult'] === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('ratings.adult_label') ?></label>
    <label><?= \App\Lang::t('ratings.warning') ?> <input name="warning_text" maxlength="300" value="<?= $this->e($row['warning_text']) ?>"></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <p><a href="/ratings"><?= \App\Lang::t('ratings.back') ?></a></p>
</section>
