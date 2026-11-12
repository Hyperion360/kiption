<?php // app/Features/Settings/views/index.php ?>
<?php $this->layout('layout'); ?>
<?php
$check = function (string $key, string $label, string $note = '') use ($current): string {
    $on = $current[$key] === '1' ? ' checked' : '';
    return "<label><input type=\"checkbox\" name=\"{$key}\" value=\"1\"{$on}> {$label}</label>"
        . ($note !== '' ? "<p class=\"chapter-meta\">{$note}</p>" : '');
};
?>
<h1><?= \App\Lang::t('settings.heading') ?></h1>
<p class="chapter-meta"><?= \App\Lang::t('settings.note') ?></p>
<form method="post" action="/settings/save" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">

  <h2 class="section-label"><?= \App\Lang::t('settings.group_basics') ?></h2>
  <label><?= \App\Lang::t('settings.site_name') ?>
    <input name="site_name" required maxlength="60" value="<?= $this->e($current['site_name']) ?>">
  </label>
  <label><?= \App\Lang::t('settings.slogan') ?>
    <input name="slogan" maxlength="120" value="<?= $this->e($current['slogan']) ?>">
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.slogan_note') ?></p>
  <label><?= \App\Lang::t('settings.items_per_page') ?>
    <input name="items_per_page" type="number" min="1" max="100" required value="<?= $this->e($current['items_per_page']) ?>">
  </label>

  <h2 class="section-label"><?= \App\Lang::t('settings.group_stories') ?></h2>
  <?= $check('submissions_open', \App\Lang::t('settings.submissions_open'), \App\Lang::t('settings.submissions_open_note')) ?>
  <?= $check('validation_required', \App\Lang::t('settings.validation_required')) ?>
  <label><?= \App\Lang::t('settings.min_words') ?>
    <input name="min_words" type="number" min="0" max="100000" value="<?= $this->e($current['min_words']) ?>">
  </label>
  <label><?= \App\Lang::t('settings.max_words') ?>
    <input name="max_words" type="number" min="0" max="1000000" value="<?= $this->e($current['max_words']) ?>">
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.words_note') ?></p>
  <?= $check('coauthors_on', \App\Lang::t('settings.coauthors_on'), \App\Lang::t('settings.coauthors_on_note')) ?>
  <?= $check('series_on', \App\Lang::t('settings.series_on'), \App\Lang::t('settings.series_on_note')) ?>
  <?= $check('uploads_on', \App\Lang::t('settings.uploads_on'), \App\Lang::t('settings.uploads_on_note')) ?>
  <label><?= \App\Lang::t('settings.default_sort') ?>
    <select name="default_sort">
      <?php foreach (['recent', 'alpha'] as $sort): ?>
      <option value="<?= $sort ?>"<?= $current['default_sort'] === $sort ? ' selected' : '' ?>><?= \App\Lang::t('settings.sort_' . $sort) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.default_sort_note') ?></p>
  <label><?= \App\Lang::t('settings.adult_rta') ?>
    <input name="adult_rta" maxlength="60" value="<?= $this->e($current['adult_rta']) ?>" placeholder="RTA-5042-1996-1400-1577-RTA">
  </label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.adult_rta_note') ?></p>

  <h2 class="section-label"><?= \App\Lang::t('settings.group_reviews') ?></h2>
  <?= $check('reviews_on', \App\Lang::t('settings.reviews_on'), \App\Lang::t('settings.reviews_on_note')) ?>
  <?= $check('guest_reviews', \App\Lang::t('settings.guest_reviews'), \App\Lang::t('settings.guest_reviews_note')) ?>
  <?= $check('rating_only', \App\Lang::t('settings.rating_only'), \App\Lang::t('settings.rating_only_note')) ?>

  <h2 class="section-label"><?= \App\Lang::t('settings.group_members') ?></h2>
  <label><?= \App\Lang::t('settings.registration_mode') ?>
    <select name="registration_mode">
      <?php foreach (['open', 'verify', 'approval', 'invite'] as $mode): ?>
      <option value="<?= $mode ?>"<?= $current['registration_mode'] === $mode ? ' selected' : '' ?>><?= \App\Lang::t('settings.mode_' . $mode) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?= $check('profiles_public', \App\Lang::t('settings.profiles_public'), \App\Lang::t('settings.profiles_public_note')) ?>

  <h2 class="section-label"><?= \App\Lang::t('settings.group_look') ?></h2>
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
  <label><?= \App\Lang::t('settings.date_format') ?>
    <select name="date_format">
      <?php foreach ($dateExamples as $key => $example): ?>
      <option value="<?= $this->e($key) ?>"<?= $current['date_format'] === $key ? ' selected' : '' ?>><?= $this->e($example) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= \App\Lang::t('settings.time_format') ?>
    <select name="time_format">
      <?php foreach ($timeExamples as $key => $example): ?>
      <option value="<?= $this->e($key) ?>"<?= $current['time_format'] === $key ? ' selected' : '' ?>><?= $this->e($example) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?= $check('powered_by', \App\Lang::t('settings.powered_by'), \App\Lang::t('settings.powered_by_note')) ?>

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
