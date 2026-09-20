<?php // app/views/browse/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Browse</h1>
<?php if ($language !== ''): ?>
  <h2>Stories in <?= $this->e($language) ?></h2>
  <ul class="story-list">
  <?php foreach ($langStories as $s): ?>
    <li>
      <a href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
      by <?= $this->e($s['penname']) ?>
      <div class="meta">
        <?= $this->e($s['rating_label']) ?>
        <?= $s['completed'] ? '<span class="badge">Complete</span>' : '<span class="badge">WIP</span>' ?>
        <?= number_format((int) $s['word_count']) ?> words
        updated <?= $this->e(substr((string) $s['updated_at'], 0, 10)) ?>
      </div>
      <p><?= $this->e($s['summary']) ?></p>
  <?php endforeach; ?>
  <?php if ($langStories === []): ?>
    <li class="meta">No stories in this language yet.</li>
  <?php endif; ?>
  </ul>
<?php endif; ?>
<ul class="story-list">
<?php foreach ($categories as $c): ?>
  <li>
    <a href="/browse/category/<?= $this->e($c['slug']) ?><?= $language !== '' ? '?language=' . $this->e($language) : '' ?>"><?= $this->e($c['name']) ?></a>
    <span class="meta"><?= (int) $c['story_count'] ?> stor<?= ((int) $c['story_count'] === 1 ? 'y' : 'ies') ?></span>
<?php endforeach; ?>
<?php if ($categories === []): ?>
  <li class="meta">No categories yet.</li>
<?php endif; ?>
</ul>
