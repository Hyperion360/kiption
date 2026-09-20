<?php // app/views/news/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($news['title']) ?></h1>
  <p class="chapter-meta">
    by <?= $this->e($news['penname'] ?? 'Anonymous') ?>
    | <?= $this->e(substr($news['published_at'], 0, 10)) ?>
  </p>
  <div class="prose"><?= \App\Markdown::render($news['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <h2 id="comments">Comments (<?= number_format((int) $comment_count) ?>)</h2>
  <?php if ($comments === []): ?><p class="chapter-meta">None yet.</p>
  <?php else: ?>
    <?php foreach ($comments as $c): ?>
      <article class="review">
        <p class="chapter-meta"><?= $this->e($c['penname'] ?? 'Anonymous') ?> | <?= $this->e($c['created_at']) ?></p>
        <div class="prose"><?= \App\Markdown::render($c['body']) ?></div>
      </article>
    <?php endforeach; ?>
    <?php if ($comment_count > count($comments)): ?><p class="chapter-meta">Showing the 50 most recent comments.</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($csrf)): ?>
    <form method="post" action="/news/comment/<?= (int) $news['id'] ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label>Comment <textarea name="body" rows="4" required maxlength="5000" placeholder="Leave a comment" aria-label="Leave a comment"></textarea></label>
      <button type="submit">Post comment</button>
    </form>
  <?php else: ?>
    <p class="chapter-meta"><a href="/auth/login">Log in</a> to comment.</p>
  <?php endif; ?>
</article>
