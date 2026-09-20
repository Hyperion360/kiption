<?php // app/views/story/read.php ?>
<?php $this->layout('layout'); ?>
<article class="h-entry">
  <header class="chapter-meta">
    <a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a>
    <?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
    | <?= \App\Lang::t('story.chapter_of', ['n' => $position, 'm' => $total]) ?>
    | <?= \App\Lang::t('story.published') ?> <time class="dt-published" datetime="<?= $this->e($story['created_at']) ?>"><?= $this->e(substr((string) $story['created_at'], 0, 10)) ?></time>
    | <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time>
  </header>
  <div class="prose e-content">
    <h1 class="p-name"><?= $this->e($chapter['title'] !== '' ? $chapter['title'] : \App\Lang::t('story.chapter_n', ['n' => $position])) ?></h1>
    <?php if (($chapter['notes_before'] ?? '') !== ''): ?>
      <div class="chapter-meta"><?= \App\Markdown::render($chapter['notes_before']) ?></div>
    <?php endif; ?>
    <?= \App\Markdown::render($chapter['content']) /* markdown at rest; raw HTML cannot be stored */ ?>
    <?php if (($chapter['notes_after'] ?? '') !== ''): ?>
      <div class="chapter-meta"><?= \App\Markdown::render($chapter['notes_after']) ?></div>
    <?php endif; ?>
  </div>
  <nav class="chapter-nav" aria-label="<?= $this->e(\App\Lang::t('story.chapter_nav_aria')) ?>">
    <?php if ($prev !== null): ?>
      <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $prev ?>"><?= \App\Lang::t('common.previous') ?></a>
    <?php else: ?><span></span><?php endif; ?>
    <?php if ($next !== null): ?>
      <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $next ?>"><?= \App\Lang::t('common.next') ?></a>
    <?php endif; ?>
  </nav>
</article>
