<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('profilefields.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('profilefields.lede') ?></p>
  <?php if (!empty($error)): ?><p class="error" role="alert"><?= \App\Lang::t($error) ?></p><?php endif; ?>
  <?php if ($fields === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('profilefields.none') ?></p>
  <?php else: ?>
  <table class="stack-table">
    <thead><tr>
      <th scope="col"><?= \App\Lang::t('profilefields.label') ?></th>
      <th scope="col"><?= \App\Lang::t('profilefields.type') ?></th>
      <th scope="col"><?= \App\Lang::t('profilefields.actions') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($fields as $f): ?>
    <tr>
      <td>
        <form method="post" action="/profilefields/rename/<?= (int) $f['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <input name="label" required maxlength="60" value="<?= $this->e($f['label']) ?>" aria-label="<?= $this->e(\App\Lang::t('profilefields.label')) ?>">
          <button type="submit"><?= \App\Lang::t('profilefields.rename') ?></button>
        </form>
      </td>
      <td data-label="<?= $this->e(\App\Lang::t('profilefields.type')) ?>"><?= \App\Lang::t('profilefields.type_' . $f['type']) ?></td>
      <td><div class="row-actions">
        <form method="post" action="/profilefields/move/<?= (int) $f['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit" name="dir" value="up"><?= \App\Lang::t('profilefields.up') ?></button>
          <button type="submit" name="dir" value="down"><?= \App\Lang::t('profilefields.down') ?></button>
        </form>
        <a class="btn" href="/profilefields/delete/<?= (int) $f['id'] ?>"><?= \App\Lang::t('profilefields.delete') ?></a>
      </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <h2><?= \App\Lang::t('profilefields.add_heading') ?></h2>
  <form method="post" action="/profilefields/add">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('profilefields.label') ?> <input name="label" required maxlength="60"></label>
    <label><?= \App\Lang::t('profilefields.type') ?>
      <select name="type">
        <option value="text"><?= \App\Lang::t('profilefields.type_text') ?></option>
        <option value="url"><?= \App\Lang::t('profilefields.type_url') ?></option>
        <option value="yesno"><?= \App\Lang::t('profilefields.type_yesno') ?></option>
      </select>
    </label>
    <button type="submit"><?= \App\Lang::t('profilefields.add') ?></button>
  </form>
</section>
