<?php // app/views/story/gate.php ?>
<?php /* The adult gate lives at the story's own URL, so this page IS the
       crawler's view of the work: it carries the story's identity (title,
       author) and the rating meta the controller stamps, with the warning
       and the continue action on top. The prose stays behind the form. */ ?>
<?php $this->layout('layout'); ?>
<div class="card">
  <p class="gate-kicker"><?= \App\Lang::t('story.gate_heading') ?></p>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="gate-by"><?= \App\Lang::t('story.by') ?> <?= $this->e($story['penname']) ?></p>
  <p><?= $this->e($story['rating_label']) ?>: <?= $this->e($story['warning_text'] ?? \App\Lang::t('story.gate_default_warning')) ?></p>
  <div class="gate-actions">
    <form method="post" action="/warning/accept" class="inline">
      <input type="hidden" name="return_to" value="<?= $this->e($returnTo) ?>">
      <button type="submit"><?= \App\Lang::t('story.gate_continue') ?></button>
    </form>
    <?= \App\Lang::t('story.gate_or') ?> <a href="/"><?= \App\Lang::t('story.gate_go_back') ?></a>
  </div>
</div>
