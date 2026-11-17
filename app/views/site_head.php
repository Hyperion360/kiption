<?php // app/views/site_head.php
/* The site header. One element tree for every width: CSS shows the desktop
   bar from 1024px and the phone bar (brand, Search, Inbox, Menu) below it.
   Everything opens and closes without scripting: Browse, the theme picker and
   the account menu are <details> (one open at a time through the shared
   name), the phone menu is the #menu :target sheet. Scripts only add Esc,
   outside click and the / shortcut. Anonymous pages are cached as static
   bytes, so a guest gets no member state here; a signed-in request gets its
   counts from the session row SessionGuard already read (no query).
   Takes loggedIn, csrf, navFile, request, navOn. */
$member = $loggedIn ?? false;
$inbox = $member ? \App\SessionGuard::unreadNotifications() + (\App\Features::on('pms') ? \App\SessionGuard::unreadMessages() : 0) : 0;
$penname = $member ? \App\SessionGuard::penname() : '';
$initial = $penname !== '' ? mb_strtoupper(mb_substr($penname, 0, 1)) : '•';
?>
  <header class="site-head hd2" data-js-module="sitehead">
    <a class="brand" href="/"><?= $this->e(\App\SiteName::get()) ?></a>
    <?php if (($tagline = \App\Settings::get('slogan')) !== ''): ?><span class="tagline"><?= $this->e($tagline) ?></span><?php endif; ?>
    <nav class="site-nav" aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <details class="nav-browse" name="site-menus">
        <summary><?= \App\Lang::t('nav.browse') ?></summary>
<?= $this->render('site_browse', ['navOn' => $navOn, 'navFile' => $navFile ?? '']) ?>
      </details>
      <?php if ($navOn['/browse/recent']): ?><a href="/browse/recent"><?= \App\Lang::t('nav.recent') ?></a><?php endif; ?>
      <?php if ($navOn['/browse/authors']): ?><a href="/browse/authors"><?= \App\Lang::t('nav.authors') ?></a><?php endif; ?>
      <?php if ($member): ?><a class="nav-account" href="/account"><?= \App\Lang::t('nav.library') ?></a>
      <?php elseif ($navOn['/series'] && \App\Settings::on('series_on')): ?><a href="/series"><?= \App\Lang::t('nav.series') ?></a><?php endif; ?>
    </nav>
    <form class="nav-search" role="search" action="/search" method="get">
      <svg class="nav-search-icon" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M12.6 12.6 17 17" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>
      <input type="search" name="q" aria-label="<?= $this->e(\App\Lang::t('nav.search')) ?>" placeholder="<?= $this->e(\App\Lang::t('nav.search_full_placeholder')) ?>">
      <kbd class="nav-kbd" aria-hidden="true">/</kbd>
    </form>
<?php if (!$member): ?>
    <details class="nav-theme" name="site-menus">
      <summary aria-label="<?= $this->e(\App\Lang::t('nav.theme_toggle')) ?>"></summary>
      <div class="nav-pop nav-theme-pop">
<?= $this->render('site_theme', ['request' => $request ?? null, 'csrf' => '']) ?>
      </div>
    </details>
    <a class="nav-account" href="/auth/login"><?= \App\Lang::t('nav.login') ?></a>
    <?php if (\App\Posting::registrationOpen()): ?><a class="nav-join" href="/auth/register"><?= \App\Lang::t('nav.join') ?></a><?php endif; ?>
<?php else: ?>
<?= $this->render('menu_post', ['class' => 'head-post']) ?>
<?php endif; ?>
    <?php /* Below 1024px the input is hidden and this plain link is the search
       affordance; members also keep their Inbox count in the phone bar. */ ?>
    <a class="nav-search-link" href="/search"><?= \App\Lang::t('nav.search') ?></a>
<?php if ($member): ?>
    <a class="nav-inbox" href="/notifications"<?php if ($inbox > 0): ?> aria-label="<?= $this->e(\App\Lang::t('nav.inbox_unread', ['n' => $inbox])) ?>"<?php endif; ?>><?= \App\Lang::t('nav.inbox') ?><?php if ($inbox > 0): ?> <b class="nav-count"><?= $inbox > 99 ? '99+' : $inbox ?></b><?php endif; ?></a>
    <details class="nav-me" name="site-menus">
      <summary aria-label="<?= $this->e(\App\Lang::t('nav.account_label')) ?>"><span class="nav-av"><?= $this->e($initial) ?></span></summary>
      <div class="nav-pop nav-me-pop">
        <div class="nav-me-head">
          <span class="nav-av nav-av-lg" aria-hidden="true"><?= $this->e($initial) ?></span>
          <div><?php if ($penname !== ''): ?><strong><?= $this->e($penname) ?></strong><?php endif; ?>
            <?php if (\App\SessionGuard::profileSlug() !== ''): ?><a href="/user/view/<?= $this->e(\App\SessionGuard::profileSlug()) ?>"><?= \App\Lang::t('nav.view_profile') ?></a><?php endif; ?></div>
        </div>
        <ul class="nav-me-list">
          <li><a href="/account"><?= \App\Lang::t('nav.library') ?></a></li>
          <li><a href="/account#your-stories"><?= \App\Lang::t('account.your_stories') ?></a></li>
          <?php if (\App\Features::on('pms')): ?><li><a href="/messages"><?= \App\Lang::t('nav.messages') ?></a></li><?php endif; ?>
          <li><a href="/account/settings"><?= \App\Lang::t('account.settings') ?></a></li>
        </ul>
        <div class="nav-me-theme"><span class="bp-label"><?= \App\Lang::t('nav.theme_toggle') ?></span>
<?= $this->render('site_theme', ['request' => $request ?? null, 'csrf' => $csrf ?? '']) ?>
        </div>
<?php $role = \App\SessionGuard::role(); if ($role === 'admin' || $role === 'moderator'): $waiting = \App\SessionGuard::waiting(); ?>
        <ul class="nav-me-list nav-me-staff">
<?php if ($role === 'admin'): ?>
          <li><a href="/manage"><span><?= \App\Lang::t('nav.manage') ?></span><?php if ($waiting > 0): ?><b class="nav-waiting"><?= \App\Lang::t('nav.waiting', ['n' => $waiting]) ?></b><?php endif; ?></a></li>
<?php endif; ?>
          <li><a href="/queue"><span><?= \App\Lang::t('nav.queue') ?></span><?php if ($role === 'moderator' && $waiting > 0): ?><b class="nav-waiting"><?= \App\Lang::t('nav.waiting', ['n' => $waiting]) ?></b><?php endif; ?></a></li>
        </ul>
<?php endif; ?>
<?php if (!empty($csrf)): ?>
        <form method="post" action="/auth/logout" class="nav-me-logout">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit" class="link-btn"><?= \App\Lang::t('nav.logout') ?></button>
        </form>
<?php endif; ?>
      </div>
    </details>
<?php endif; ?>
    <a class="nav-menu-link<?= $member ? ' is-member' : '' ?>" href="#menu"><?php if ($member): ?><span class="nav-av" aria-hidden="true"><?= $this->e($initial) ?></span> <?php endif; ?><?= \App\Lang::t('nav.menu') ?></a>
  </header>
