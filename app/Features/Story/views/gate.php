<?php // app/views/story/gate.php ?>
<?php $this->layout('layout'); ?>
<div class="card">
  <h1><?= \App\Lang::t('story.gate_heading') ?></h1>
  <p><?= $this->e($story['rating_label']) ?>: <?= $this->e($story['warning_text'] ?? \App\Lang::t('story.gate_default_warning')) ?></p>
  <p>
    <form method="post" action="/warning/accept" class="inline">
      <input type="hidden" name="return_to" value="<?= $this->e($returnTo) ?>">
      <button type="submit"><?= \App\Lang::t('story.gate_continue') ?></button>
    </form>
    <?= \App\Lang::t('story.gate_or') ?> <a href="/"><?= \App\Lang::t('story.gate_go_back') ?></a>
  </p>
</div>
