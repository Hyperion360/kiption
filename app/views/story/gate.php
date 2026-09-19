<?php // app/views/story/gate.php ?>
<?php $this->layout('layout'); ?>
<div class="card">
  <h1>Content warning</h1>
  <p><?= $this->e($story['rating_label']) ?>: <?= $this->e($story['warning_text'] ?? 'This story contains adult content.') ?></p>
  <p>
    <a href="/warning/accept?return_to=<?= $this->e($returnTo) ?>">I understand, continue</a>
    or <a href="/">go back</a>
  </p>
</div>
