<?php // app/Features/Feed/views/subscribe.php ?>
<?php // The page behind the footer's Feed link: what a feed is in one line,
     // the two archive-wide addresses as copyable text, and one feed per
     // category. Every address is also a rel=alternate link so reader
     // extensions can pick it up. ?>
<?php $this->layout('layout'); ?>
<div class="page">
  <header class="page-head">
    <h1><?= \App\Lang::t('feed.subscribe_heading') ?></h1>
    <p class="lede"><?= \App\Lang::t('feed.subscribe_lede') ?></p>
  </header>
  <h2 class="section-label"><?= \App\Lang::t('feed.whole_archive') ?></h2>
  <ul class="row-list feed-rows">
    <li><a href="/feed" type="application/atom+xml" rel="alternate"><span class="row-title"><?= \App\Lang::t('feed.atom') ?></span><code class="row-meta"><?= $this->e($base . '/feed') ?></code></a></li>
    <?php if (\App\Features::on('feeds')): ?>
    <li><a href="/rss" type="application/rss+xml" rel="alternate"><span class="row-title"><?= \App\Lang::t('feed.rss') ?></span><code class="row-meta"><?= $this->e($base . '/rss') ?></code></a></li>
    <?php endif; ?>
  </ul>
  <?php if ($categories !== []): ?>
  <h2 class="section-label"><?= \App\Lang::t('feed.by_category') ?></h2>
  <ul class="row-list feed-rows">
    <?php foreach ($categories as $c): ?>
    <li><a href="/feed/category/<?= $this->e($c['slug']) ?>" type="application/atom+xml"><span class="row-title"><?= $this->e($c['name']) ?></span><code class="row-meta"><?= $this->e($base . '/feed/category/' . $c['slug']) ?></code></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p class="field-hint feed-note"><?= \App\Lang::t('feed.author_note') ?></p>
</div>
