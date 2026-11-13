<?php /* The page body every layout wraps the same way, so a skin never
   drifts from the default: on owner screens the admin shell (sidebar, trail,
   phone tab bar), on staff screens the Saved line with its Undo. Takes
   content, isAdmin, staff, request and csrf. */ ?>
  <?php /* Owner screens only: the list of every admin screen (a sidebar on
     wide screens, a tab bar and sheet on phones) and the way back to the
     list a sub-screen belongs to. Members and guests never get these bytes. */
     $isAdminScreen = array_key_exists('isAdmin', get_defined_vars()) ? $isAdmin : \App\SessionGuard::role() === 'admin';
     $admin = $isAdminScreen ? \App\AdminNav::shell((string) (($request ?? null)?->path ?? '')) : null;
     if ($admin !== null): ?>
  <div class="admin-shell" data-js-module="adminforms" data-count-text="<?= $this->e(\App\Lang::t('admin.count')) ?>">
  <?= $this->render('admin_nav', ['admin' => $admin]) ?>
  <div class="admin-body">
  <?php if (count($admin['trail']) > 1): // a sub-screen: the list it belongs to. The sidebar and tab bar already lead home. ?>
  <nav class="admin-trail" aria-label="<?= $this->e(\App\Lang::t('manage.trail_label')) ?>">
    <?php foreach ($admin['trail'] as $step): ?><a href="<?= $this->e($step['url']) ?>"><?= \App\Lang::t($step['label']) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
  <?php /* The answer to a save: the redirect carried a short code and this is its line. Staff screens only. */
     $savedKey = ($admin !== null || ($staff ?? false)) ? \App\Saved::message($request ?? null) : null;
     if ($savedKey !== null): ?>
  <?php $undo = \App\Saved::undo($request ?? null, (string) ($csrf ?? '')); ?>
  <?php if ($undo === null): ?>
  <p class="notice saved-note" role="status" data-js-module="saved"><?= \App\Lang::t($savedKey) ?></p>
  <?php else: ?>
  <div class="notice saved-note saved-note-undo" role="status" data-js-module="saved">
    <span><?= \App\Lang::t($savedKey) ?></span>
    <form method="post" action="<?= $this->e($undo) ?>" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit" class="saved-undo"><?= \App\Lang::t('admin.undo') ?></button>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <main class="site-main"><?= $content ?? '' ?></main>
  <?php if ($admin !== null): ?>
  </div>
  </div>
  <?= $this->render('admin_tabs', ['admin' => $admin]) ?>
  <?php endif; ?>
