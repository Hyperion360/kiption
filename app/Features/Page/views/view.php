<?php // app/views/page/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($page['title']) ?></h1>
  <div class="prose"><?= \App\Markdown::render($page['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <p class="chapter-meta"><?= \App\Lang::t('page.last_updated', ['date' => $this->e(\App\Dates::format($page['updated_at']))]) ?></p>
</article>
