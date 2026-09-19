<?php // app/views/story/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="chapter-meta">
    by <?= $this->e($story['penname']) ?>
    | <?= $this->e($story['rating_label']) ?>
    <?php if ((int) $story['is_adult'] === 1): ?><span class="badge">Adult</span><?php endif; ?>
    <?= $story['completed'] ? '<span class="badge">Complete</span>' : '<span class="badge">WIP</span>' ?>
    | <?= number_format((int) $story['word_count']) ?> words
    | in <?= $this->e($story['category_names'] ?? 'Uncategorized') ?>
  </p>
  <p><?= $this->e($story['summary']) ?></p>
  <?php if (($story['notes'] ?? '') !== ''): ?>
    <div class="chapter-meta"><?= \App\Markdown::render($story['notes']) ?></div>
  <?php endif; ?>
  <h2>Chapters</h2>
  <ol>
    <?php foreach ($chapters as $c): ?>
      <li>
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>">
          Chapter <?= (int) $c['position'] ?>: <?= $this->e($c['title']) ?></a>
        <span class="chapter-meta">(<?= number_format((int) $c['word_count']) ?> words)</span>
      </li>
    <?php endforeach; ?>
  </ol>
</article>
