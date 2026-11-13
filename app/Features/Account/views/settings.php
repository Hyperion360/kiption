<?php // app/Features/Account/views/settings.php ?>
<?php // Settings (split from the Library): profile, display and notification
     // preferences, one form per concern, then the muted authors. Every form
     // posts to its existing endpoint and returns here. ?>
<?php $this->layout('layout'); ?>
<section class="page">
  <header class="page-head">
    <p class="eyebrow"><a href="/account"><?= \App\Lang::t('account.library_heading') ?></a></p>
    <h1><?= \App\Lang::t('account.settings') ?></h1>
    <p class="lede"><?= $this->e($me['a']) ?> · <?= $this->e($me['b']) ?> · <span class="badge"><?= $this->e($me['c']) ?></span></p>
  </header>
  <?php if (!empty($notice)): ?><p class="notice" role="status"><?= \App\Lang::t($notice, $notice === 'saved.penname' ? ['name' => $this->e($me['a'])] : []) ?><?= $notice === 'saved.penname' && !empty($liveSoon) ? ' ' . \App\Lang::t('saved.live_soon_note') : '' ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><p class="error" role="alert"><?= \App\Lang::t($error, $errorParams ?? []) ?></p><?php endif; ?>
  <div class="settings-group">
  <h2 class="section-label"><?= \App\Lang::t('account.section_profile') ?></h2>
  <form method="post" action="/account/penname">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label for="penname"><?= \App\Lang::t('account.penname_label') ?></label>
    <input id="penname" name="penname" value="<?= $this->e($pennameValue ?? $me['a'] ?? '') ?>" minlength="2" maxlength="40" required aria-describedby="penname-help<?= !empty($pennameError) ? ' penname-error' : '' ?>"<?= !empty($pennameError) ? ' aria-invalid="true"' : '' ?> autocomplete="nickname">
    <?php if (!empty($pennameError)): ?><p id="penname-error" class="field-error" role="alert"><?= $this->e($pennameError) ?></p><?php endif; ?>
    <p id="penname-help" class="hint"><?= \App\Lang::t('account.penname_help') ?></p>
    <button type="submit" class="btn-secondary"><?= \App\Lang::t('account.penname_save') ?></button>
  </form>

  <?php if (!empty($me['d'])): ?>
    <img class="avatar" src="<?= $this->e($me['d']) ?>" alt="<?= $this->e(\App\Lang::t('account.avatar_alt')) ?>" width="80" height="80">
  <?php endif; ?>
  <?php if (\App\Settings::on('uploads_on')): ?>
  <form method="post" action="/account/avatar" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.avatar_label') ?> <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <button type="submit" class="btn-secondary"><?= \App\Lang::t('account.upload') ?></button>
  </form>
  <?php endif; ?>
  <form method="post" action="/account/support">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.support_label') ?> <input name="support_url" maxlength="200" value="<?= $this->e($me['e'] ?? '') ?>"></label>
    <button type="submit" class="btn-secondary"><?= \App\Lang::t('account.support_save') ?></button>
  </form>
  <form method="post" action="/account/prefs">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.bio_label') ?> <textarea name="bio" maxlength="2000" rows="4"><?= $this->e($me['g'] ?? '') ?></textarea></label>
    <label><input type="checkbox" name="is_beta" value="1"<?= (int) ($me['h'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.beta_pref') ?></label>
    <h2 class="section-label"><?= \App\Lang::t('account.section_reading') ?></h2>
    <fieldset>
      <legend><?= \App\Lang::t('account.sort_legend') ?></legend>
      <label><input type="radio" name="default_sort" value="recent"<?= ($me['i'] ?? 'recent') !== 'alpha' ? ' checked' : '' ?>> <?= \App\Lang::t('account.sort_recent') ?></label>
      <label><input type="radio" name="default_sort" value="alpha"<?= ($me['i'] ?? '') === 'alpha' ? ' checked' : '' ?>> <?= \App\Lang::t('account.sort_alpha') ?></label>
    </fieldset>
    <?php // Both 12d field groups are flag-gated (the mute off-hides precedent):
         // off hides the inputs, and prefs() leaves the stored columns alone. ?>
    <?php // Stored rows carry paper/sepia/night (026); auto is indistinguishable
         // from a stored paper, so the stored value checks its own radio and
         // anything else falls back to auto checked. ?>
    <?php if (\App\Features::on('perusertheme')): ?>
    <fieldset>
      <legend><?= \App\Lang::t('account.theme_legend') ?></legend>
      <label><input type="radio" name="theme" value="paper"<?= ($themePref ?? '') === 'paper' ? ' checked' : '' ?>> <?= \App\Lang::t('theme.paper') ?></label>
      <label><input type="radio" name="theme" value="sepia"<?= ($themePref ?? '') === 'sepia' ? ' checked' : '' ?>> <?= \App\Lang::t('theme.sepia') ?></label>
      <label><input type="radio" name="theme" value="night"<?= ($themePref ?? '') === 'night' ? ' checked' : '' ?>> <?= \App\Lang::t('theme.night') ?></label>
      <label><input type="radio" name="theme" value="auto"<?= !\in_array($themePref ?? '', ['paper', 'sepia', 'night'], true) ? ' checked' : '' ?>> <?= \App\Lang::t('theme.auto') ?></label>
    </fieldset>
    <?php endif; ?>
    <?php if (\App\Features::on('peruserlang')): ?>
    <label><?= \App\Lang::t('account.lang_label') ?> <select name="lang">
      <option value=""<?= ($langPref ?? '') === '' ? ' selected' : '' ?>><?= \App\Lang::t('account.lang_follow') ?></option>
      <?php foreach (\App\Lang::installed() as $code): ?>
      <option value="<?= $this->e($code) ?>"<?= ($langPref ?? '') === $code ? ' selected' : '' ?>><?= $this->e($code) ?></option>
      <?php endforeach; ?>
    </select></label>
    <?php endif; ?>
    <?php // toc stays cookie-driven: the cookie is the value read() actually consumes ?>
    <label><input type="checkbox" name="toc_first" value="1"<?= !empty($tocOn) ? ' checked' : '' ?>> <?= \App\Lang::t('account.toc_first') ?></label>
    <h2 class="section-label"><?= \App\Lang::t('account.section_email') ?></h2>
    <label><input type="checkbox" name="notify_review" value="1"<?= (int) ($me['j'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_review') ?></label>
    <label><input type="checkbox" name="notify_response" value="1"<?= (int) ($me['l'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_response') ?></label>
    <label><input type="checkbox" name="notify_favorites" value="1"<?= (int) ($me['m'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_favorites') ?></label>
    <label><input type="checkbox" name="notify_favorite_digest" value="1"<?= (int) ($me['f'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_digest') ?></label>
    <button type="submit"><?= \App\Lang::t('account.save_prefs') ?></button>
  </form>
    </div>
  <?php if (!empty($profileFields)): ?>
  <div class="settings-group">
  <h2 class="section-label"><?= \App\Lang::t('account.section_fields') ?></h2>
  <form method="post" action="/account/fields">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php foreach ($profileFields as $pf):
      $pfName = 'field[' . (int) $pf['id'] . ']';
      $pfKind = $pf['type'] === 'url' ? 'url' : 'text';
      $pfMax = $pf['type'] === 'url' ? 500 : 200;
      $pfOn = $pf['value'] === '1' ? ' checked' : ''; ?>
    <?php if ($pf['type'] === 'yesno'): ?>
    <label><input type="checkbox" value="1" name="<?= $this->e($pfName) ?>"<?= $pfOn ?>> <?= $this->e($pf['label']) ?></label>
    <?php else: ?>
    <label><?= $this->e($pf['label']) ?> <input <?= 'type="' . $pfKind . '" maxlength="' . $pfMax . '" name="' . $this->e($pfName) . '" value="' . $this->e($pf['value']) . '"' ?>></label>
    <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit"><?= \App\Lang::t('account.fields_save') ?></button>
  </form>
  </div>
  <?php endif; ?>
  <div class="settings-group">
  <h2 class="section-label"><?= \App\Lang::t('account.section_security') ?></h2>
  <form method="post" action="/account/password">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <h3><?= \App\Lang::t('account.password_heading') ?></h3>
    <label><?= \App\Lang::t('account.current_password') ?> <input type="password" name="current_password" required autocomplete="current-password"></label>
    <label><?= \App\Lang::t('account.new_password') ?> <input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
    <label><?= \App\Lang::t('account.confirm_new_password') ?> <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password"></label>
    <button type="submit"><?= \App\Lang::t('account.password_save') ?></button>
  </form>
  <form method="post" action="/account/email">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <h3><?= \App\Lang::t('account.email_heading') ?></h3>
    <label><?= \App\Lang::t('account.new_email') ?> <input type="email" name="new_email" required maxlength="254" autocomplete="email"></label>
    <label><?= \App\Lang::t('account.current_password') ?> <input type="password" name="current_password" required autocomplete="current-password"></label>
    <button type="submit"><?= \App\Lang::t('account.email_save') ?></button>
  </form>
  <p><a href="/account/delete"><?= \App\Lang::t('account.delete_link') ?></a></p>
  </div>
  <?php if (\App\Features::on('mute')): ?>
  <h2 class="section-label"><?= \App\Lang::t('account.muted') ?></h2>
  <?php if ($muted === []): ?><p class="empty-state"><?= \App\Lang::t('account.nobody_muted') ?></p>
  <?php else: ?>
  <p class="chapter-meta"><?= \App\Lang::t('account.muted_note') ?></p>
  <ul>
    <?php foreach ($muted as $m): ?>
      <li>
        <a href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= $this->e($m['penname']) ?></a>
        <form method="post" action="/mute/remove/<?= $this->e($m['profile_slug']) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('account.unmute') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php endif; ?>
</section>
