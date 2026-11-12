<?php // app/views/maintenance.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= \App\Lang::t('maintenance.title') ?></title>
</head>
<body>
  <main style="max-width:40rem;margin:4rem auto;padding:0 1rem;font-family:Georgia,serif;">
    <h1><?= \App\Lang::t('maintenance.title') ?></h1>
    <?php if (\App\Notices::has('msg.maintenance')): ?><?= \App\Notices::html('msg.maintenance') /* Markdown, every character escaped */ ?><?php else: ?><p><?= \App\Lang::t('maintenance.body') ?></p><?php endif; ?>
  </main>
</body>
</html>
