<?php // app/skins/classic/views/browse/index.php ?>
<?php // The classic skin's browse index: list-first and dense. The default's
     // page head shrinks to one heading line, the lede drops, and the
     // categories render as a compact two-column row list (skin CSS carries
     // the column rhythm). Everything a controller sends is still read; the
     // language block keeps the default card vocabulary.
     $this->layout('layout'); ?>
<div class="page skin-listing">
  <header class="page-head">
    <h1><?= \App\Lang::t('browse.heading') ?></h1>
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
  <ul class="row-list skin-cat-grid">
<?php foreach ($categories as $c): ?>
    <li><a href="/browse/category/<?= $this->e($c['slug']) ?><?= $language !== '' ? '?language=' . $this->e($language) : '' ?>"><span class="row-title"><?= $this->e($c['name']) ?></span><span class="row-meta"><?= (int) $c['story_count'] ?> <?= \App\Lang::t((int) $c['story_count'] === 1 ? 'browse.story' : 'browse.stories') ?></span></a></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <p class="section-more"><a href="/browse/authors"><?= \App\Lang::t('browse.authors') ?></a></p>
</div>
