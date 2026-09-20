<?php // app/views/lists/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('lists.new') : \App\Lang::t('lists.edit') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/lists/create' : '/lists/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="120" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="500" rows="3"><?= $this->e($row['summary'] ?? '') ?></textarea></label>
    <label><input type="checkbox" name="is_public" value="1"<?= (int) ($row['is_public'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('lists.public_label') ?></label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
</section>
