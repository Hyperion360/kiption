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
  <?php /* The WordPress-style generator declaration: one static meta line
         naming the software, so "is this site Powered by Kiption?" is
         answerable from the head. No version string: a published version
         number is fingerprinting for free (attack surface), and it would
         also bake stale into cached pages. Gated on the attribution seam -
         the same powered_by opt-out that hides the footer hides this too,
         so one operator switch governs the whole advertisement. Cost per
         uncached render is one static bool read; cached pages bake the
         line and never execute it. */ ?>
  <?php if (\App\Attribution::on()): ?><meta name="generator" content="Kiption"><?php endif; ?>
  <title><?= $this->e(isset($head) && $head !== null ? $head->title() : ($title ?? \App\SiteName::get())) ?></title>
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
  <?php // The archive feed's own name, never the page's: a reader listing two
     // autodiscovered feeds (this one plus a profile or category feed) must
     // not see the archive carrying the page's title. ?>
  <link rel="alternate" type="application/atom+xml" title="<?= $this->e(\App\Lang::t('common.feed_title')) ?>" href="/feed">
  <?php endif; ?>
  <link rel="icon" href="<?= \App\AssetUrl::icon('favicon.ico') ?>" sizes="32x32">
  <link rel="icon" href="<?= \App\AssetUrl::icon('favicon.svg') ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= \App\AssetUrl::icon('apple-touch-icon.png') ?>">
  <link rel="stylesheet" href="<?= \App\AssetUrl::for('reader.css') ?>">
  <?= $this->render('_look_link') ?>
  <script src="<?= \App\AssetUrl::for('app.js') ?>" defer></script>
</head>
<body<?= ($focus ?? false) ? ' class="focus"' : '' ?>>
<?php if (($isAdmin ?? false) && \App\Maintenance::on()): ?>
  <p class="maintenance-banner" role="status"><?= \App\Lang::t('maintenance.banner') ?> <a href="/settings"><?= \App\Lang::t('maintenance.banner_link') ?></a></p>
<?php endif; ?>
  <a class="skip-link" href="#main"><?= \App\Lang::t('nav.skip') ?></a>
  <?php $navOn = \App\NavLinks::builtIns($navFile ?? ''); ?>
<?= $this->render('site_head', ['loggedIn' => $loggedIn ?? false, 'csrf' => $csrf ?? '', 'navFile' => $navFile ?? '', 'request' => $request ?? null, 'navOn' => $navOn]) ?>
  <?= $this->render('admin_main', ['content' => $content, 'isAdmin' => $isAdmin ?? false, 'staff' => $staff ?? false,
      'request' => $request ?? null, 'csrf' => $csrf ?? '']) ?>
<?= $this->render('site_foot', ['navOn' => $navOn, 'navFile' => $navFile ?? '']) ?>
<?= $this->render('site_menu', ['loggedIn' => $loggedIn ?? false, 'csrf' => $csrf ?? '', 'navFile' => $navFile ?? '', 'request' => $request ?? null, 'navOn' => $navOn]) ?>
</body>
</html>
