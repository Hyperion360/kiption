<?php // app/views/browse/index.php ?>
<?php // Browse (undesigned in the comps): the page head, then categories as
     // serif rows with their story counts (the chapter-list row vocabulary),
     // and, when ?language= is set, that language's stories as M6 cards.
     $this->layout('layout'); ?>
<div class="page">
  <header class="page-head">
    <h1><?= \App\Lang::t('browse.heading') ?></h1>
    <p class="lede"><?= \App\Lang::t('browse.lede') ?></p>
  </header>
<?php if ($language !== ''): ?>
  <h2 class="section-label"><?= \App\Lang::t('browse.stories_in', ['language' => $this->e($language)]) ?></h2>
  <?php if ($langStories === []): ?>
  <p class="empty-state"><?= \App\Lang::t('browse.no_stories_language') ?></p>
  <?php else: ?>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $langStories]) ?>
  </ul>
  <?php endif; ?>
<?php endif; ?>
  <h2 class="section-label"><?= \App\Lang::t('browse.categories') ?></h2>
<?php if ($categories === []): ?>
  <p class="empty-state"><?= \App\Lang::t('browse.no_categories') ?></p>
<?php else: ?>
  <ul class="row-list">
<?php foreach ($categories as $c): ?>
    <li><a href="/browse/category/<?= $this->e($c['slug']) ?><?= $language !== '' ? '?language=' . $this->e($language) : '' ?>"><span class="row-title"><?= $this->e($c['name']) ?></span><span class="row-meta"><?= (int) $c['story_count'] ?> <?= \App\Lang::t((int) $c['story_count'] === 1 ? 'browse.story' : 'browse.stories') ?></span></a></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <p class="section-more"><a href="/browse/authors"><?= \App\Lang::t('browse.authors') ?></a></p>
</div>
