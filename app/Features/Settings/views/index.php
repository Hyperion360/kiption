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
  <label><?= \App\Lang::t('settings.ui_lang') ?>
    <select name="ui_lang">
      <?php foreach (($languages ?? []) as $l): ?>
      <option value="<?= $this->e($l['code']) ?>"<?= $current['ui_lang'] === $l['code'] ? ' selected' : '' ?>><?= $this->e($l['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.ui_lang_note') ?></p>
  <label><?= \App\Lang::t('settings.adult_rta') ?>
    <input name="adult_rta" maxlength="60" value="<?= $this->e($current['adult_rta']) ?>" placeholder="RTA-5042-1996-1400-1577-RTA">
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.adult_rta_note') ?></p>
  <input type="hidden" name="footer_present" value="1">
  <label><?= \App\Lang::t('settings.footer_copyright') ?>
    <input name="footer_copyright" maxlength="60" value="<?= $this->e($current['footer_copyright']) ?>">
  </label>
  <label><?= \App\Lang::t('settings.footer_copyright_url') ?>
    <input name="footer_copyright_url" maxlength="300" value="<?= $this->e($current['footer_copyright_url']) ?>">
  </label>
  <label><?= \App\Lang::t('settings.footer_links') ?>
    <textarea name="footer_links" rows="4"><?= $this->e($current['footer_links']) ?></textarea>
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.footer_links_note') ?></p>
  <label><?= \App\Lang::t('settings.og_image') ?>
    <select name="og_image">
      <option value=""><?= \App\Lang::t('settings.og_image_none') ?></option>
      <?php foreach (($images ?? []) as $img): ?>
      <option value="<?= $this->e($img) ?>"<?= $current['og_image'] === $img ? ' selected' : '' ?>><?= $this->e(basename($img)) ?></option>
      <?php endforeach; ?>
      <?php if ($current['og_image'] !== '' && !in_array($current['og_image'], $images ?? [], true)): ?>
      <option value="<?= $this->e($current['og_image']) ?>" selected><?= $this->e($current['og_image']) ?></option>
      <?php endif; ?>
    </select>
  </label>
  <label><input type="checkbox" name="maintenance_on" value="1"<?= $current['maintenance_on'] === '1' ? ' checked' : '' ?>> <?= \App\Lang::t('settings.maintenance_on') ?></label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.maintenance_note') ?></p>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save') ?></button>
</form>
