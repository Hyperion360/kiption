<?php // app/views/page/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($page['title']) ?></h1>
  <div class="prose"><?= \App\Markdown::render($page['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <p class="chapter-meta">Last updated <?= $this->e(substr($page['updated_at'], 0, 10)) ?></p>
</article>
