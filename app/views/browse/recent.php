<?php // app/views/browse/recent.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title ?? 'Recently updated') ?></h1>
<ul class="story-list">
<?php foreach ($stories as $s): ?>
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
<?php if ($stories === []): ?>
  <li class="meta">No stories yet.</li>
<?php endif; ?>
</ul>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?>?page=<?= $page - 1 ?>">Newer</a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?>?page=<?= $page + 1 ?>">Older</a>
