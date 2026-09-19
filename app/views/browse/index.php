<?php // app/views/browse/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Browse</h1>
<ul class="story-list">
<?php foreach ($categories as $c): ?>
  <li>
    <a href="/browse/category/<?= $this->e($c['slug']) ?>"><?= $this->e($c['name']) ?></a>
    <span class="meta"><?= (int) $c['story_count'] ?> stor<?= ((int) $c['story_count'] === 1 ? 'y' : 'ies') ?></span>
<?php endforeach; ?>
<?php if ($categories === []): ?>
  <li class="meta">No categories yet.</li>
<?php endif; ?>
</ul>
