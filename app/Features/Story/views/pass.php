<?php // app/Features/Story/views/pass.php ?>
<?php /* The post-password twin of gate.php: this page IS the crawler's
       view of the locked work (title, byline, no prose), with the password
       form on top. The kicker names the gate for what it is (a protected
       work, not a content warning). Anonymous renders are byte-identical,
       so the static cache may hold the form; the unlocked render is
       cookie-bearing and never cached. */ ?>
<?php $this->layout('layout'); ?>
<div class="card">
  <p class="gate-kicker"><?= \App\Lang::t('story.pass_heading') ?></p>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="gate-by"><?= \App\Lang::t('story.by') ?> <?= $this->e($story['penname']) ?></p>
  <?php if ($wrong): ?><p class="gate-warning"><?= \App\Lang::t('story.pass_wrong') ?></p><?php endif; ?>
  <form method="post" action="/reader/unlock" class="gate-pass">
    <input type="hidden" name="table" value="<?= $this->e($table) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="return_to" value="<?= $this->e($return_to) ?>">
    <label for="pass-field"><?= \App\Lang::t('story.pass_prompt') ?></label>
    <input id="pass-field" type="password" name="password" autocomplete="off" required>
    <div class="gate-actions">
      <button type="submit"><?= \App\Lang::t('story.pass_continue') ?></button>
      <a href="/"><?= \App\Lang::t('story.gate_go_back') ?></a>
    </div>
  </form>
</div>
