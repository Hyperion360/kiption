<?php // app/Features/Categories/views/edit.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('categories.edit_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" class="form-card" action="/categories/update/<?= (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('categories.name') ?> <input name="name" required maxlength="100" value="<?= $this->e($row['name']) ?>"></label>
    <label><?= \App\Lang::t('categories.description') ?> <textarea name="description" rows="2" maxlength="500"><?= $this->e($row['description']) ?></textarea></label>
    <label><?= \App\Lang::t('categories.parent') ?>
      <select name="parent_id">
        <option value=""><?= \App\Lang::t('categories.top_level') ?></option>
        <?php foreach ($options as $o): ?>
        <option value="<?= (int) $o['id'] ?>"<?= (int) ($row['parent_id'] ?? 0) === (int) $o['id'] ? ' selected' : '' ?>><?= $this->e($o['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="inline"><input type="checkbox" name="locked" value="1"<?= (int) $row['locked'] === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('categories.locked_label') ?></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <p><a href="/categories"><?= \App\Lang::t('categories.back') ?></a></p>
</section>
