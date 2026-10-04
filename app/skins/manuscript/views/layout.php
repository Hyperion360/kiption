<?php // app/skins/manuscript/views/layout.php ?>
<?php // The manuscript skin's layout: the app default's structure with the skin
     // stylesheet wired in and a body-level hook class. manuscript.css loads
     // AFTER reader.css, so its custom-property palette and compact type
     // scale win by cascade order while every component rule it does not
     // restyle keeps the default look. Kept deliberately close to the app
     // layout: a skin diverges only where it means to (SKINS.md).
     $dir = \App\Lang::dir();
     $attrs = \App\Features\Reader\Prefs::current($request ?? null)->dataAttrs(); ?>
<!doctype html>
<html lang="<?= $this->e(\App\Lang::current()) ?>"<?= $dir !== null ? ' dir="' . $this->e($dir) . '"' : '' ?><?= ($theme ?? null) !== null ? ' data-theme="' . $this->e($theme) . '"' : '' ?><?= $attrs ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e(isset($head) && $head !== null ? $head->title() : ($title ?? \App\Lang::t('nav.brand'))) ?></title>
  <?php if (isset($head) && $head !== null): ?>
  <?php foreach ($head->metaTags() as $t): ?>
  <meta name="<?= $this->e($t['name']) ?>" content="<?= $this->e($t['content']) ?>">
  <?php endforeach; ?>
  <?php foreach ($head->ogTags() as $t): ?>
  <meta property="<?= $this->e($t['property']) ?>" content="<?= $this->e($t['content']) ?>">
  <?php endforeach; ?>
  <?php foreach ($head->twitterTags() as $t): ?>
  <meta name="<?= $this->e($t['name']) ?>" content="<?= $this->e($t['content']) ?>">
  <?php endforeach; ?>
  <?php if ($head->rendersCanonicalLink()): ?><link rel="canonical" href="<?= $this->e($head->canonical()) ?>"><?php endif; ?>
  <?php if ($head->jsonLd() !== ''): ?>
  <script type="application/ld+json"><?= $head->jsonLd() /* JSON_HEX_TAG makes this safe */ ?></script>
  <?php endif; ?>
  <?php else: ?>
  <link rel="canonical" href="/">
  <?php endif; ?>
  <?php if (\App\Features::on('feeds')): ?>
  <link rel="alternate" type="application/atom+xml" title="<?= $this->e($title ?? \App\Lang::t('common.feed_title')) ?>" href="/feed">
  <?php endif; ?>
  <link rel="stylesheet" href="/assets/reader.css">
  <link rel="stylesheet" href="/assets/skins/manuscript.css">
  <script src="/assets/app.js" defer></script>
</head>
<body class="skin-manuscript<?= ($focus ?? false) ? ' focus' : '' ?>">
  <header class="site-head">
    <a class="brand" href="/"><?= \App\Lang::t('nav.brand') ?></a>
    <nav class="site-nav" aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <a href="/browse"><?= \App\Lang::t('nav.browse') ?></a>
      <a href="/browse/recent"><?= \App\Lang::t('nav.recent') ?></a>
      <a class="nav-wide" href="/browse/authors"><?= \App\Lang::t('nav.authors') ?></a>
      <a class="nav-wide" href="/series"><?= \App\Lang::t('nav.series') ?></a>
      <a class="nav-inline-search" href="/search"><?= \App\Lang::t('nav.search') ?></a>
    </nav>
    <form class="nav-search" role="search" action="/search" method="get">
      <input type="search" name="q" aria-label="<?= $this->e(\App\Lang::t('nav.search')) ?>" placeholder="<?= $this->e(\App\Lang::t('nav.search_placeholder')) ?>">
    </form>
    <a class="nav-account" href="<?= ($loggedIn ?? false) ? '/account' : '/auth/login' ?>"><?= \App\Lang::t(($loggedIn ?? false) ? 'nav.library' : 'nav.login') ?></a>
    <a class="nav-search-link" href="/search"><?= \App\Lang::t('nav.search') ?></a>
    <button type="button" class="theme-toggle" data-js-module="toggle" aria-label="<?= $this->e(\App\Lang::t('nav.theme_toggle')) ?>" data-label-dark="<?= $this->e(\App\Lang::t('nav.theme_to_dark')) ?>" data-label-light="<?= $this->e(\App\Lang::t('nav.theme_to_light')) ?>"></button>
    <a class="nav-menu-link<?= ($loggedIn ?? false) ? ' is-member' : '' ?>" href="#menu"><?= \App\Lang::t('nav.menu') ?></a>
  </header>
  <main class="site-main"><?= $content ?></main>
  <?php $footSeen = ['/' => 1, '/browse' => 1, '/browse/recent' => 1, '/browse/authors' => 1, '/series' => 1, '/search' => 1,
                     '/account' => 1, '/auth/login' => 1, '/news' => 1, '/top' => 1, '/lists' => 1, '/challenges' => 1, '/feed' => 1]; ?>
  <footer class="site-foot">
    <p class="foot-brand"><a href="/"><?= \App\Lang::t('nav.brand') ?></a> <span><?= \App\Lang::t('foot.built_with') ?> <a href="https://github.com/Hyperion360/kip" rel="noopener"><?= \App\Lang::t('foot.kip') ?></a></span></p>
    <nav class="foot-nav" aria-label="<?= $this->e(\App\Lang::t('foot.more_label')) ?>">
      <?php if (\App\Features::on('news')): ?><a href="/news"><?= \App\Lang::t('news.heading') ?></a><?php endif; ?>
      <?php if (\App\Features::on('toplists')): ?><a href="/top"><?= \App\Lang::t('top.heading') ?></a><?php endif; ?>
      <?php if (\App\Features::on('lists')): ?><a href="/lists"><?= \App\Lang::t('foot.lists') ?></a><?php endif; ?>
      <?php if (\App\Features::on('challenges')): ?><a href="/challenges"><?= \App\Lang::t('challenges.index') ?></a><?php endif; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): if (isset($footSeen[$l['url']])) continue; ?>
      <a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a>
<?php endforeach; ?>
      <?php if (\App\Features::on('feeds')): ?><a href="/feed/subscribe"><?= \App\Lang::t('common.feed_title') ?></a><?php endif; ?>
    </nav>
    <?php if (\App\Attribution::on()): ?>
    <p class="poweredby"><a href="<?= $this->e(\App\Attribution::url()) ?>"><?= \App\Lang::t('foot.powered_by') ?></a></p>
    <?php endif; ?>
  </footer>
  <span id="sheet-close" class="skip-close" tabindex="-1"></span>
  <div class="scrim" aria-hidden="true"></div>
  <div id="menu" class="sheet" role="dialog" aria-label="<?= $this->e(\App\Lang::t('nav.menu')) ?>">
    <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a>
    <a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
    <?php $menuRead = ['/browse' => \App\Lang::t('nav.browse'), '/browse/recent' => \App\Lang::t('nav.recent'),
                       '/browse/authors' => \App\Lang::t('nav.authors'), '/series' => \App\Lang::t('nav.series'),
                       '/search' => \App\Lang::t('nav.search')];
       $menuSeen = $menuRead + ['/account' => '', '/messages' => '', '/notifications' => '', '/auth/login' => '']; ?>
    <nav aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <?php if ($loggedIn ?? false): ?>
      <span class="sheet-group"><?= \App\Lang::t('nav.you') ?></span>
      <a href="/account"><?= \App\Lang::t('nav.library') ?></a>
      <?php if (\App\Features::on('pms')): ?><a href="/messages"><?= \App\Lang::t('nav.messages') ?></a><?php endif; ?>
      <a href="/notifications"><?= \App\Lang::t('nav.notifications') ?></a>
      <a href="/account/settings"><?= \App\Lang::t('account.settings') ?></a>
      <?php endif; ?>
      <span class="sheet-group"><?= \App\Lang::t('nav.read') ?></span>
      <?php foreach ($menuRead as $menuUrl => $menuLabel): ?>
      <a href="<?= $menuUrl ?>"><?= $menuLabel ?></a>
      <?php endforeach; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): if (isset($menuSeen[$l['url']])) continue; ?>
      <a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a>
<?php endforeach; ?>
      <?php if (!($loggedIn ?? false)): ?>
      <a href="/auth/login"><?= \App\Lang::t('nav.login') ?></a>
      <?php endif; ?>
      <?php if ($isAdmin ?? false): ?>
      <span class="sheet-group"><?= \App\Lang::t('nav.operator') ?></span>
      <a href="/admin"><?= \App\Lang::t('nav.admin') ?></a>
      <a href="/queue"><?= \App\Lang::t('nav.queue') ?></a>
      <?php if (\App\Features::on('news')): ?><a href="/news/new"><?= \App\Lang::t('nav.post_news') ?></a><?php endif; ?>
      <?php endif; ?>
    </nav>
    <?php if (($loggedIn ?? false) && !empty($csrf)): ?>
    <form method="post" action="/auth/logout" class="sheet-logout">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit" class="link-btn"><?= \App\Lang::t('nav.logout') ?></button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
