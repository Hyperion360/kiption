<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Kiption</h1>
<p>
  A self-hosted fiction archive. This instance is being set up; stories,
  chapters, and the reading experience arrive with the next milestones.
</p>
<?php if ($loggedIn): ?>
<form method="post" action="/auth/logout">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button>Log out</button>
</form>
<?php endif; ?>
