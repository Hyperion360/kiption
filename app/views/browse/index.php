<?php // app/views/browse/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('browse.heading') ?></h1>
<?php if ($language !== ''): ?>
  <h2><?= \App\Lang::t('browse.stories_in', ['language' => $this->e($language)]) ?></h2>
  <ul class="story-list">
  <?php foreach ($langStories as $s): ?>
    <li>
      <a href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <?= $this->e($s['penname']) ?>
      <div class="meta">
        <?= $this->e($s['rating_label']) ?>
        <?php if ($s['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?>
        <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?>
        <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $s['updated_at'], 0, 10))]) ?>
      </div>
      <p><?= $this->e($s['summary']) ?></p>
  <?php endforeach; ?>
  <?php if ($langStories === []): ?>
    <li class="meta"><?= \App\Lang::t('browse.no_stories_language') ?></li>
  <?php endif; ?>
  </ul>
<?php endif; ?>
<ul class="story-list">
<?php foreach ($categories as $c): ?>
  <li>
    <a href="/browse/category/<?= $this->e($c['slug']) ?><?= $language !== '' ? '?language=' . $this->e($language) : '' ?>"><?= $this->e($c['name']) ?></a>
    <span class="meta"><?= (int) $c['story_count'] ?> <?= \App\Lang::t((int) $c['story_count'] === 1 ? 'browse.story' : 'browse.stories') ?></span>
<?php endforeach; ?>
<?php if ($categories === []): ?>
  <li class="meta"><?= \App\Lang::t('browse.no_categories') ?></li>
<?php endif; ?>
</ul>
