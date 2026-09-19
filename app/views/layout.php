<?php // app/views/layout.php ?>
<!doctype html>
<html lang="en"<?= ($theme ?? null) !== null ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Kiption') ?></title>
  <link rel="stylesheet" href="/assets/reader.css">
</head>
<body>
  <header class="site-head">
    <nav class="site-nav" aria-label="Site">
      <a class="brand" href="/">Kiption</a>
      <a href="/browse">Browse</a>
      <a href="/browse/recent">Recent</a>
      <a href="/auth/login">Log in</a>
    </nav>
  </header>
  <main class="site-main"><?= $content ?></main>
  <footer class="site-foot">
    Powered by Kiption
    <span class="theme-toggle">Theme:
      <a href="/theme/dark?return_to=<?= $this->e($path ?? '/') ?>">dark</a> |
      <a href="/theme/light?return_to=<?= $this->e($path ?? '/') ?>">light</a>
    </span>
  </footer>
</body>
</html>
