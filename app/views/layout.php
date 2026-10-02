<?php // app/views/layout.php ?>
<?php $dir = \App\Lang::dir(); // pack-declared: 'rtl' or null (ltr stays attribute-less)
     // Reader typography prefs ride the envelope's request (Step 2b sweep);
     // a render that still omits it reads all defaults, and a cookieless
     // request emits nothing, so cached pages stay byte-stable.
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
</head>
<body<?= ($focus ?? false) ? ' class="focus"' : '' ?>>
  <header class="site-head">
    <a class="brand" href="/"><?= \App\Lang::t('nav.brand') ?></a>
    <nav class="site-nav" aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <a href="/browse"><?= \App\Lang::t('nav.browse') ?></a>
      <a href="/browse/recent"><?= \App\Lang::t('nav.recent') ?></a>
      <?php /* hidden below 1024 (reader.css): the nav-search-link beside Menu is the sub-desktop search affordance; without this class the tablet header carries Search twice */ ?>
      <a class="nav-inline-search" href="/search"><?= \App\Lang::t('nav.search') ?></a>
      <a class="nav-account" href="<?= ($loggedIn ?? false) ? '/account' : '/auth/login' ?>"><?= \App\Lang::t(($loggedIn ?? false) ? 'nav.account' : 'nav.login') ?></a>
    </nav>
    <form class="nav-search" role="search" action="/search" method="get">
      <input type="search" name="q" aria-label="<?= $this->e(\App\Lang::t('nav.search')) ?>" placeholder="<?= $this->e(\App\Lang::t('nav.search_placeholder')) ?>">
    </form>
    <?php /* Below 1024px the search input is hidden and this plain link is
       the search affordance, sitting between the nav and the Menu control;
       the desktop stylesheet swaps the link back out for the form. */ ?>
    <a class="nav-search-link" href="/search"><?= \App\Lang::t('nav.search') ?></a>
    <a class="nav-menu-link" href="#menu"><?= \App\Lang::t('nav.menu') ?></a>
  </header>
  <main class="site-main"><?= $content ?></main>
  <?php /* No footer: the comp ships none on any frame. Theme and text
     settings live in the reader's Text sheet (C8); the menu sheet below
     carries every navigation link. The span is the sheets' shared close
     target: it sits in a fixed 1px box, so landing on it never scrolls;
     Done links point here instead of "#" (which jumps to the document
     top and loses a mid-chapter reading position). */ ?>
  <span id="sheet-close" class="skip-close" tabindex="-1"></span>
  <div class="scrim" aria-hidden="true"></div>
  <div id="menu" class="sheet" role="dialog" aria-label="<?= $this->e(\App\Lang::t('nav.menu')) ?>">
    <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a>
    <a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
    <nav aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <a href="/browse"><?= \App\Lang::t('nav.browse') ?></a>
      <a href="/browse/recent"><?= \App\Lang::t('nav.recent') ?></a>
      <a href="/search"><?= \App\Lang::t('nav.search') ?></a>
      <?php if ($loggedIn ?? false): ?>
        <?php if (\App\Features::on('pms')): ?><a href="/messages"><?= \App\Lang::t('nav.messages') ?></a><?php endif; ?>
        <a href="/notifications"><?= \App\Lang::t('nav.notifications') ?></a>
        <a href="/account"><?= \App\Lang::t('nav.account') ?></a>
      <?php else: ?>
        <a href="/auth/login"><?= \App\Lang::t('nav.login') ?></a>
      <?php endif; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): ?>
      <a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a>
<?php endforeach; ?>
      <?php if ($isAdmin ?? false): ?>
      <a href="/admin"><?= \App\Lang::t('nav.admin') ?></a>
      <a href="/queue"><?= \App\Lang::t('nav.queue') ?></a>
      <?php if (\App\Features::on('news')): ?><a href="/news/new"><?= \App\Lang::t('nav.post_news') ?></a><?php endif; ?>
      <?php endif; ?>
    </nav>
  </div>
</body>
</html>
