<?php // app/Features/Story/views/pass.php ?>
<?php /* The post-password twin of gate.php: this page IS the crawler's
       view of the locked work (title, byline, no prose), with the password
       form on top. Anonymous renders are byte-identical, so the static
       cache may hold the form; the unlocked render is cookie-bearing and
       never cached. */ ?>
<?php $this->layout('layout'); ?>
<div class="card">
  <p class="gate-kicker"><?= \App\Lang::t('story.gate_heading') ?></p>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="gate-by"><?= \App\Lang::t('story.by') ?> <?= $this->e($story['penname']) ?></p>
  <?php if ($wrong): ?><p class="gate-warning"><?= \App\Lang::t('story.pass_wrong') ?></p><?php endif; ?>
  <div class="gate-actions">
    <form method="post" action="/reader/unlock" class="inline">
      <input type="hidden" name="table" value="<?= $this->e($table) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="return_to" value="<?= $this->e($return_to) ?>">
      <label><?= \App\Lang::t('story.pass_prompt') ?> <input type="password" name="password" autocomplete="off" required></label>
      <button type="submit"><?= \App\Lang::t('story.pass_continue') ?></button>
    </form>
    <a href="/"><?= \App\Lang::t('story.gate_go_back') ?></a>
  </div>
</div>
