<?php // app/views/home/index.php ?>
<?php // The home page on the Kip blog's S1 pattern: the archive's name as the
     // headline, one plain sentence, a strong rule, then the featured shelf
     // (when an editor picked any) and the latest stories as M6 cards. Both
     // lists come from the controller's one UNION ALL query.
     $this->layout('layout'); ?>
<div class="page page-home">
  <header class="page-head">
    <h1><?= \App\Lang::t('nav.brand') ?></h1>
    <p class="lede"><?= \App\Lang::t('home.intro') ?></p>
  </header>
<?php if (!empty($continue)): ?>
  <section class="home-section" aria-labelledby="home-continue">
    <h2 class="section-label" id="home-continue"><?= \App\Lang::t('reader.continue') ?></h2>
    <a class="continue-cta home-continue" href="/story/read/<?= $this->e($continue['slug']) ?>/<?= (int) $continue['resume'] ?>">
      <span class="continue-story"><?= $this->e($continue['title']) ?></span>
      <span class="continue-sub"><?= \App\Lang::t('home.continue_sub', ['roman' => \App\Features\Reader\Roman::numeral((int) $continue['resume']), 'pct' => min(100, (int) round(100 * (int) $continue['words_before'] / max(1, (int) $continue['words_total'])))]) ?></span>
    </a>
  </section>
<?php endif; ?>
<?php if (($featured ?? []) !== []): ?>
  <section class="home-section" aria-labelledby="home-featured">
    <h2 class="section-label" id="home-featured"><?= \App\Lang::t('home.featured') ?></h2>
    <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $featured, 'fresh' => $loggedIn]) ?>
    </ul>
  </section>
<?php endif; ?>
  <section class="home-section" aria-labelledby="home-latest">
    <h2 class="section-label" id="home-latest"><?= \App\Lang::t('home.latest') ?></h2>
<?php if (($latest ?? []) === []): ?>
    <p class="empty-state"><?= \App\Lang::t('story.no_stories') ?></p>
<?php else: ?>
    <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $latest, 'fresh' => $loggedIn]) ?>
    </ul>
    <p class="section-more"><a href="/browse/recent"><?= \App\Lang::t('home.all_recent') ?></a></p>
<?php endif; ?>
  </section>
</div>
