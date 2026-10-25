<?php // app/views/series/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('series.new') : \App\Lang::t('series.edit') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/series/create' : '/series/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="120" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="2000" rows="3"><?= $this->e($row['summary'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('series.membership') ?>
      <select name="membership">
        <?php foreach (['open', 'moderated', 'closed'] as $m): ?>
          <option value="<?= $m ?>"<?= ($row['membership'] ?? 'open') === $m ? ' selected' : '' ?>><?= $this->e(\App\Lang::t('series.m_' . $m)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
  <?php if ($row !== null): ?>
  <form method="post" action="/series/cover/<?= $this->e($row['slug']) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('series.cover_label') ?> <input type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <button type="submit"><?= \App\Lang::t('series.upload_cover') ?></button>
  </form>
  <?php endif; ?>
</section>
