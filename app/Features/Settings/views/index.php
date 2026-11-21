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
<p class="lede"><?= \App\Lang::t('settings.note') ?></p>
<nav class="settings-jump" aria-label="<?= $this->e(\App\Lang::t('settings.jump_label')) ?>">
  <?php foreach (array_keys(\App\Features\Settings\SettingsController::SECTIONS) as $name): ?>
  <a href="#s-<?= $name ?>"><?= \App\Lang::t('settings.group_' . $name) ?></a>
  <?php endforeach; ?>
</nav>

<section class="settings-section" id="s-basics">
<form method="post" action="/settings/save#s-basics" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="basics">
  <h2><?= \App\Lang::t('settings.group_basics') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_basics') ?></p>
  <?php if (($errorSection ?? '') === 'basics'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <p class="chapter-meta"><?= \App\Lang::t('settings.penname_pointer') ?> <a href="/account/settings#penname"><?= \App\Lang::t('settings.penname_pointer_link') ?></a></p>
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
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_basics')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-stories">
<form method="post" action="/settings/save#s-stories" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="stories">
  <h2><?= \App\Lang::t('settings.group_stories') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_stories') ?></p>
  <?php if (($errorSection ?? '') === 'stories'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <?= $check('submissions_open', \App\Lang::t('settings.submissions_open'), \App\Lang::t('settings.submissions_open_note')) ?>
  <?= $check('validation_required', \App\Lang::t('settings.validation_required')) ?>
  <p class="consequence"><?= \App\Lang::t($waiting['queue'] === 0 ? 'settings.waiting_queue_none' : ($waiting['queue'] === 1 ? 'settings.waiting_queue_one' : 'settings.waiting_queue'), ['n' => $waiting['queue']]) ?>
    <?php if ($waiting['queue'] !== 0): ?><a href="/queue"><?= \App\Lang::t('settings.waiting_link') ?></a><?php endif; ?></p>
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
  <?= $check('chapter_images_on', \App\Lang::t('settings.chapter_images_on'), \App\Lang::t('settings.chapter_images_on_note')) ?>
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
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_stories')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-reviews">
<form method="post" action="/settings/save#s-reviews" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="reviews">
  <h2><?= \App\Lang::t('settings.group_reviews') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_reviews') ?></p>
  <?php if (($errorSection ?? '') === 'reviews'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <?= $check('reviews_on', \App\Lang::t('settings.reviews_on'), \App\Lang::t('settings.reviews_on_note')) ?>
  <?= $check('guest_reviews', \App\Lang::t('settings.guest_reviews'), \App\Lang::t('settings.guest_reviews_note')) ?>
  <?= $check('rating_only', \App\Lang::t('settings.rating_only'), \App\Lang::t('settings.rating_only_note')) ?>
  <?= $check('review_scores_on', \App\Lang::t('settings.review_scores_on'), \App\Lang::t('settings.review_scores_on_note')) ?>
  <?= $check('categories_on', \App\Lang::t('settings.categories_on'), \App\Lang::t('settings.categories_on_note')) ?>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_reviews')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-members">
<form method="post" action="/settings/save#s-members" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="members">
  <h2><?= \App\Lang::t('settings.group_members') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_members') ?></p>
  <?php if (($errorSection ?? '') === 'members'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <label><?= \App\Lang::t('settings.registration_mode') ?>
    <select name="registration_mode">
      <?php foreach (['open', 'verify', 'approval', 'invite'] as $mode): ?>
      <option value="<?= $mode ?>"<?= $current['registration_mode'] === $mode ? ' selected' : '' ?>><?= \App\Lang::t('settings.mode_' . $mode) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="consequence"><?= \App\Lang::t($waiting['members'] === 0 ? 'settings.waiting_members_none' : ($waiting['members'] === 1 ? 'settings.waiting_members_one' : 'settings.waiting_members'), ['n' => $waiting['members']]) ?>
    <?php if ($waiting['members'] !== 0): ?><a href="/queue"><?= \App\Lang::t('settings.waiting_link') ?></a><?php endif; ?></p>
  <?= $check('profiles_public', \App\Lang::t('settings.profiles_public'), \App\Lang::t('settings.profiles_public_note')) ?>
  <label><?= \App\Lang::t('settings.tag_policy') ?>
    <select name="tag_policy">
      <?php foreach (['off', 'suggest', 'free'] as $policy): ?>
      <option value="<?= $policy ?>"<?= $current['tag_policy'] === $policy ? ' selected' : '' ?>><?= \App\Lang::t('settings.tag_policy_' . $policy) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="consequence"><?= \App\Lang::t('settings.tag_policy_note') ?></p>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_members')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-look">
<form method="post" action="/settings/save#s-look" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="look">
  <h2><?= \App\Lang::t('settings.group_look') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_look') ?></p>
  <?php if (($errorSection ?? '') === 'look'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
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
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_look')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-footer">
<form method="post" action="/settings/save#s-footer" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="footer">
  <h2><?= \App\Lang::t('settings.group_footer') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_footer') ?></p>
  <?php if (($errorSection ?? '') === 'footer'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
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
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_footer')]) ?></button>
</form>
</section>

<section class="settings-section" id="s-maintenance">
<form method="post" action="/settings/save#s-maintenance" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="section" value="maintenance">
  <h2><?= \App\Lang::t('settings.group_maintenance') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('settings.about_maintenance') ?></p>
  <?php if (($errorSection ?? '') === 'maintenance'): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <label><input type="checkbox" name="maintenance_on" value="1"<?= $current['maintenance_on'] === '1' ? ' checked' : '' ?>> <?= \App\Lang::t('settings.maintenance_on') ?></label>
  <p class="chapter-meta"><?= \App\Lang::t('settings.maintenance_note') ?></p>
  <div class="consequence">
    <p><?= \App\Lang::t($current['maintenance_on'] === '1' ? 'settings.maintenance_now_on' : 'settings.maintenance_now_off') ?></p>
    <blockquote><strong><?= \App\Lang::t('maintenance.title') ?></strong><br><?= \App\Lang::t('maintenance.body') ?></blockquote>
  </div>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('settings.save_section', ['section' => \App\Lang::t('settings.group_maintenance')]) ?></button>
</form>
</section>
