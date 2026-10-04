<?php // app/Features/Settings/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('settings.heading') ?></h1>
<p class="chapter-meta"><?= \App\Lang::t('settings.note') ?></p>
<form method="post" action="/settings/save" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label><?= \App\Lang::t('settings.site_name') ?>
    <input name="site_name" required maxlength="60" value="<?= $this->e($current['site_name']) ?>">
  </label>
  <label><?= \App\Lang::t('settings.registration_mode') ?>
    <select name="registration_mode">
      <?php foreach (['open', 'verify', 'approval', 'invite'] as $mode): ?>
      <option value="<?= $mode ?>"<?= $current['registration_mode'] === $mode ? ' selected' : '' ?>><?= \App\Lang::t('settings.mode_' . $mode) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= \App\Lang::t('settings.items_per_page') ?>
    <input name="items_per_page" type="number" min="1" max="100" required value="<?= $this->e($current['items_per_page']) ?>">
  </label>
  <label><input type="checkbox" name="validation_required" value="1"<?= $current['validation_required'] === '1' ? ' checked' : '' ?>> <?= \App\Lang::t('settings.validation_required') ?></label>
  <label><input type="checkbox" name="powered_by" value="1"<?= $current['powered_by'] === '1' ? ' checked' : '' ?>> <?= \App\Lang::t('settings.powered_by') ?></label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.powered_by_note') ?></p>
  <label><?= \App\Lang::t('settings.skin') ?>
    <select name="skin">
      <?php foreach (($skins ?? [\App\Skins::DEFAULT]) as $skinName): ?>
      <option value="<?= $this->e($skinName) ?>"<?= $current['skin'] === $skinName ? ' selected' : '' ?>><?= $this->e($skinName) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.skin_note') ?></p>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save') ?></button>
</form>
