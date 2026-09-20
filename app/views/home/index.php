<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Kiption</h1>
<p>
  A self-hosted fiction archive. This instance is being set up; stories,
  chapters, and the reading experience arrive with the next milestones.
</p>
<?php if (($featured ?? []) !== []): ?>
<section>
  <h2>Featured</h2>
  <ul>
<?php foreach ($featured as $f): ?>
    <li>
      <a href="/story/view/<?= $this->e($f['slug']) ?>"><?= $this->e($f['title']) ?></a>
      <?php if ($f['summary'] !== ''): ?><span class="chapter-meta"><?= $this->e($f['summary']) ?></span><?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php if ($loggedIn): ?>
<form method="post" action="/auth/logout">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button>Log out</button>
</form>
<?php endif; ?>
