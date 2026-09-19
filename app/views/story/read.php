<?php // app/views/story/read.php ?>
<?php $this->layout('layout'); ?>
<article>
  <header class="chapter-meta">
    <a href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a>
    by <?= $this->e($story['penname']) ?>
    | Chapter <?= $position ?> of <?= $total ?>
  </header>
  <div class="prose">
    <h1><?= $this->e($chapter['title'] !== '' ? $chapter['title'] : 'Chapter ' . $position) ?></h1>
    <?php if (($chapter['notes_before'] ?? '') !== ''): ?>
      <p class="chapter-meta"><?= $this->e($chapter['notes_before']) ?></p>
    <?php endif; ?>
    <?= $chapter['content'] /* sanitized at write time; seed and Plan 5 writers guarantee this */ ?>
    <?php if (($chapter['notes_after'] ?? '') !== ''): ?>
      <p class="chapter-meta"><?= $this->e($chapter['notes_after']) ?></p>
    <?php endif; ?>
  </div>
  <nav class="chapter-nav" aria-label="Chapter navigation">
    <?php if ($prev !== null): ?>
      <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $prev ?>">Previous</a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if ($next !== null): ?>
      <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $next ?>">Next</a>
    <?php endif; ?>
  </nav>
</article>
