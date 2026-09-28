<?php // app/views/story/whole.php ?>
<?php $this->layout('layout'); ?>
<?php /* The whole-work reading view doubles as the print view: the media="print"
   stylesheet is pure CSS plus the browser print command (no JS, no ?print=
   URL variant). The read beacon deliberately does NOT embed here: reads count
   per chapter only, and the whole view is not a chapter read. */ ?>
<link rel="stylesheet" media="print" href="/assets/print.css">
<article class="h-entry whole-work">
  <header class="chapter-meta">
    <h1><a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a></h1>
    <?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
    | <?= $this->e($story['rating_label']) ?>
    <?php if ((int) $story['is_adult'] === 1): ?><span class="badge"><?= \App\Lang::t('story.adult') ?></span><?php endif; ?>
    | <?= number_format((int) $story['word_count']) ?> <?= \App\Lang::t('story.words') ?>
    | <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time>
    <span class="print-hint"><?= \App\Lang::t('story.whole_print_hint') ?></span>
  </header>
  <nav class="whole-toc" aria-label="<?= $this->e(\App\Lang::t('story.whole_toc_aria')) ?>">
    <h2><?= \App\Lang::t('story.whole_toc') ?></h2>
    <ol>
      <?php foreach ($chapters as $c): ?>
      <li><a href="#ch-<?= (int) $c['position'] ?>"><?= \App\Lang::t('story.chapter_n', ['n' => (int) $c['position']]) ?>: <?= $this->e($c['title']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <?php foreach ($chapters as $c): ?>
  <section class="prose" id="ch-<?= (int) $c['position'] ?>">
    <h2 class="p-name"><?= $this->e($c['title'] !== '' ? $c['title'] : \App\Lang::t('story.chapter_n', ['n' => (int) $c['position']])) ?></h2>
    <?= \App\Markdown::render($c['content']) /* markdown at rest; raw HTML cannot be stored */ ?>
  </section>
  <?php endforeach; ?>
</article>
