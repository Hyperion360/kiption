<?php // app/views/site_menu.php
/* The phone menu (below 1024px): a :target sheet, so it opens from the Menu
   link with scripting off. A guest gets search, the Join card, the Read list,
   Write and the theme control; a member gets the profile row, four tiles, the
   Post button, the Read list, staff entries, theme and Log out. The desktop
   bar owns all of this from 1024px, where the sheet stays hidden.
   Takes loggedIn, csrf, navFile, request, navOn. */
$member = $loggedIn ?? false;
$penname = $member ? \App\SessionGuard::penname() : '';
$initial = $penname !== '' ? mb_strtoupper(mb_substr($penname, 0, 1)) : '•';
$inbox = $member ? \App\SessionGuard::unreadNotifications() + (\App\Features::on('pms') ? \App\SessionGuard::unreadMessages() : 0) : 0;
$menuRead = ['/browse' => \App\Lang::t('nav.browse')];
if ($navOn['/browse/recent']) $menuRead['/browse/recent'] = \App\Lang::t('nav.way_recent');
if ($navOn['/browse/authors']) $menuRead['/browse/authors'] = \App\Lang::t('nav.authors');
if ($navOn['/series'] && \App\Settings::on('series_on')) $menuRead['/series'] = \App\Lang::t('nav.series');
if (\App\Features::on('toplists')) $menuRead['/top'] = \App\Lang::t('top.heading');
if (\App\Features::on('lists')) $menuRead['/lists'] = \App\Lang::t('foot.lists');
if (\App\Features::on('challenges')) $menuRead['/challenges'] = \App\Lang::t('challenges.index');
if (\App\Features::on('news')) $menuRead['/news'] = \App\Lang::t('news.heading');
$menuSeen = $menuRead + ['/search' => '', '/story/new' => '', '/account' => '', '/messages' => '', '/notifications' => '', '/auth/login' => '',
                         '/browse/recent' => '', '/browse/authors' => '', '/series' => '', '/feed' => ''];
$categoryCount = count(\App\BrowsePanel::read(\App\BrowsePanel::fileFor($navFile ?? ''))['categories']);
?>
  <span id="sheet-close" class="skip-close" tabindex="-1"></span>
  <div class="scrim" aria-hidden="true"></div>
  <div id="menu" class="sheet" role="dialog" aria-label="<?= $this->e(\App\Lang::t('nav.menu')) ?>">
    <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a>
    <div class="sheet-bar"><span class="sheet-title"><?= \App\Lang::t('nav.menu') ?></span><a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a></div>
<?php if ($member): ?>
    <a class="sheet-profile" href="<?= \App\SessionGuard::profileSlug() !== '' ? '/user/view/' . $this->e(\App\SessionGuard::profileSlug()) : '/account' ?>">
      <span class="nav-av nav-av-lg" aria-hidden="true"><?= $this->e($initial) ?></span>
      <span><?php if ($penname !== ''): ?><strong><?= $this->e($penname) ?></strong><?php endif; ?><small><?= \App\Lang::t('nav.view_profile') ?></small></span>
    </a>
    <div class="sheet-tiles">
      <a href="/account"><strong><?= \App\Lang::t('nav.library') ?></strong></a>
      <a href="/notifications"><strong><?= \App\Lang::t('nav.inbox') ?></strong><?php if ($inbox > 0): ?><b class="nav-count"><?= $inbox > 99 ? '99+' : $inbox ?></b><?php endif; ?></a>
      <a href="/account#your-stories"><strong><?= \App\Lang::t('account.your_stories') ?></strong></a>
      <a href="/account/settings"><strong><?= \App\Lang::t('account.settings') ?></strong></a>
    </div>
<?= $this->render('menu_post', ['class' => 'sheet-post']) ?>
<?php else: ?>
    <form class="sheet-search" role="search" action="/search" method="get">
      <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12.6 12.6 17 17" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>
      <input type="search" name="q" aria-label="<?= $this->e(\App\Lang::t('nav.search')) ?>" placeholder="<?= $this->e(\App\Lang::t('nav.search_full_placeholder')) ?>">
    </form>
    <div class="sheet-join">
      <p><?= \App\Lang::t('nav.join_blurb') ?></p>
      <div><?php if (\App\Posting::registrationOpen()): ?><a class="sheet-join-go" href="/auth/register"><?= \App\Lang::t('nav.join') ?></a><?php endif; ?><a class="sheet-join-login" href="/auth/login"><?= \App\Lang::t('nav.login') ?></a></div>
    </div>
<?php endif; ?>
    <nav aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <span class="sheet-group"><?= \App\Lang::t('nav.read') ?></span>
<?php foreach ($menuRead as $menuUrl => $menuLabel): ?>
      <a href="<?= $menuUrl ?>"><span><?= $menuLabel ?></span><?php if ($menuUrl === '/browse' && $categoryCount > 0 && !$member): ?><small><?= \App\Lang::t('nav.categories_count', ['n' => $categoryCount]) ?></small><?php endif; ?></a>
<?php endforeach; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): if (isset($menuSeen[$l['url']])) continue; ?>
      <a href="<?= $this->e($l['url']) ?>"><span><?= $this->e($l['label']) ?></span></a>
<?php endforeach; ?>
    </nav>
<?php if (!$member && \App\Posting::guestOffered()): ?>
    <span class="sheet-group"><?= \App\Lang::t('nav.write') ?></span>
<?= $this->render('menu_post', ['guest' => true, 'class' => 'sheet-post']) ?>
<?php endif; ?>
<?php $role = $member ? \App\SessionGuard::role() : ''; if ($role === 'admin' || $role === 'moderator'): $waiting = \App\SessionGuard::waiting(); ?>
    <div class="sheet-staff">
<?php if ($role === 'admin'): ?>
      <a href="/manage"><span><?= \App\Lang::t('nav.manage') ?></span><?php if ($waiting > 0): ?><b class="nav-waiting"><?= \App\Lang::t('nav.waiting', ['n' => $waiting]) ?></b><?php endif; ?></a>
<?php endif; ?>
      <a href="/queue"><span><?= \App\Lang::t('nav.queue') ?></span><?php if ($role === 'moderator' && $waiting > 0): ?><b class="nav-waiting"><?= \App\Lang::t('nav.waiting', ['n' => $waiting]) ?></b><?php endif; ?></a>
    </div>
<?php endif; ?>
    <span class="sheet-group"><?= \App\Lang::t('nav.theme_toggle') ?></span>
<?= $this->render('site_theme', ['request' => $request ?? null, 'csrf' => $member ? ($csrf ?? '') : '']) ?>
<?php if ($member && !empty($csrf)): ?>
    <form method="post" action="/auth/logout" class="sheet-logout">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit" class="link-btn"><?= \App\Lang::t('nav.logout') ?></button>
    </form>
<?php endif; ?>
  </div>
