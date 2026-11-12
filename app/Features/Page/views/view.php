<?php // app/views/page/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($page['title']) ?></h1>
  <div class="prose"><?= \App\Markdown::render($page['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <?php if (!empty($isAdmin)): ?><p class="chapter-meta"><a href="/page/edit/<?= $this->e($page['slug']) ?>"><?= \App\Lang::t('page.edit_link') ?></a></p><?php endif; ?>
  <p class="chapter-meta"><?= \App\Lang::t('page.last_updated', ['date' => $this->e(substr($page['updated_at'], 0, 10))]) ?></p>
</article>
