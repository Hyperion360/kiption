<?php // app/Features/Characters/views/edit.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('characters.edit_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/characters/update/<?= (int) $character['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="80" value="<?= $this->e($character['name']) ?>"></label>
    <label><?= \App\Lang::t('characters.category') ?>
      <select name="category_id">
        <option value="0"<?= $character['category_id'] === null ? ' selected' : '' ?>><?= \App\Lang::t('characters.any_category') ?></option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= (int) $cat['id'] ?>"<?= (int) $character['category_id'] === (int) $cat['id'] ? ' selected' : '' ?>><?= $this->e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('characters.description') ?> <input name="description" maxlength="500" value="<?= $this->e($character['description']) ?>"></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <p><a href="/characters"><?= \App\Lang::t('taxonomy.back') ?></a></p>
</section>
