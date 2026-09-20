<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('nav.brand') ?></h1>
<p><?= \App\Lang::t('home.intro') ?></p>
<?php if (($featured ?? []) !== []): ?>
<section>
  <h2><?= \App\Lang::t('home.featured') ?></h2>
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
  <button><?= \App\Lang::t('nav.logout') ?></button>
</form>
<?php endif; ?>
