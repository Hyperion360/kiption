<?php // app/views/_look_link.php: the owner's colors and layout, loaded after the skin sheet ?>
<?php if (($lookHref = \App\Look\Boot::href()) !== ''): ?>
  <link rel="stylesheet" href="<?= $this->e($lookHref) ?>">
<?php endif; ?>
