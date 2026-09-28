<?php // app/views/layout.php ?>
<?php $dir = \App\Lang::dir(); // pack-declared: 'rtl' or null (ltr stays attribute-less) ?>
<!doctype html>
<html lang="<?= $this->e(\App\Lang::current()) ?>"<?= $dir !== null ? ' dir="' . $this->e($dir) . '"' : '' ?><?= ($theme ?? null) !== null ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
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
<body>
  <header class="site-head">
    <nav class="site-nav" aria-label="<?= $this->e(\App\Lang::t('nav.site_label')) ?>">
      <a class="brand" href="/"><?= \App\Lang::t('nav.brand') ?></a>
      <a href="/browse"><?= \App\Lang::t('nav.browse') ?></a>
      <a href="/browse/recent"><?= \App\Lang::t('nav.recent') ?></a>
      <a href="/auth/login"><?= \App\Lang::t('nav.login') ?></a>
      <?php if ($loggedIn ?? false): ?><?php if (\App\Features::on('pms')): ?><a href="/messages"><?= \App\Lang::t('nav.messages') ?></a><?php endif; ?><a href="/notifications"><?= \App\Lang::t('nav.notifications') ?></a><?php endif; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): ?>
    <a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a>
<?php endforeach; ?>
    <?php if ($isAdmin ?? false): ?>
    <a href="/admin"><?= \App\Lang::t('nav.admin') ?></a>
    <a href="/queue"><?= \App\Lang::t('nav.queue') ?></a>
    <?php if (\App\Features::on('news')): ?><a href="/news/new"><?= \App\Lang::t('nav.post_news') ?></a><?php endif; ?>
    <?php endif; ?>
    </nav>
  </header>
  <main class="site-main"><?= $content ?></main>
  <footer class="site-foot">
    <?= \App\Lang::t('footer.powered_by') ?>
    <span class="theme-toggle"><?= \App\Lang::t('footer.theme') ?>
      <a href="/theme/dark?return_to=<?= $this->e($path ?? '/') ?>"><?= \App\Lang::t('theme.dark') ?></a> |
      <a href="/theme/light?return_to=<?= $this->e($path ?? '/') ?>"><?= \App\Lang::t('theme.light') ?></a>
    </span>
  </footer>
</body>
</html>
