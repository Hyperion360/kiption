<?php // app/views/news/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($news['title']) ?></h1>
  <p class="chapter-meta">
    <?= \App\Lang::t('story.by') ?> <?= $this->e($news['penname'] ?? \App\Lang::t('common.anonymous')) ?>
    | <?= $this->e(substr($news['published_at'], 0, 10)) ?>
  </p>
  <div class="prose"><?= \App\Markdown::render($news['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <h2 id="comments"><?= \App\Lang::t('news.comments_heading') . ' (' . number_format((int) $comment_count) . ')' ?></h2>
  <?php if ($comments === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?></p>
  <?php else: ?>
    <?php foreach ($comments as $c): ?>
      <article class="review">
        <p class="chapter-meta"><?= $this->e($c['penname'] ?? \App\Lang::t('common.anonymous')) ?> | <?= $this->e($c['created_at']) ?></p>
        <div class="prose"><?= \App\Markdown::render($c['body']) ?></div>
      </article>
    <?php endforeach; ?>
    <?php if ($comment_count > count($comments)): ?><p class="chapter-meta"><?= \App\Lang::t('news.recent_50') ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($csrf) && \App\Features::on('comments')): ?>
    <form method="post" action="/news/comment/<?= (int) $news['id'] ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('news.comment_label') ?> <textarea name="body" rows="4" required maxlength="5000" placeholder="<?= $this->e(\App\Lang::t('news.comment_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('news.comment_placeholder')) ?>"></textarea></label>
      <button type="submit"><?= \App\Lang::t('news.post_comment') ?></button>
    </form>
  <?php elseif (\App\Features::on('comments')): ?>
    <p class="chapter-meta"><a href="/auth/login"><?= \App\Lang::t('nav.login') ?></a> <?= \App\Lang::t('news.to_comment') ?></p>
  <?php endif; ?>
</article>
