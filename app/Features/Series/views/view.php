<?php // app/views/series/view.php ?>
<?php $this->layout('layout'); ?>
<section class="page">
  <header class="page-head">
  <p class="eyebrow"><?= \App\Lang::t('series.eyebrow') ?></p>
  <h1><?= $this->e($series['title']) ?></h1>
  <p class="byline">
    <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($series['owner_slug']) ?>"><?= $this->e($series['owner_penname']) ?></a>
    · <?= \App\Lang::t('common.works_count', ['n' => number_format((int) $series['item_count'])]) ?>
    · <?= $this->e(substr((string) $series['created_at'], 0, 10)) ?>
  </p>
  <?php if ($series['summary'] !== ''): ?><p class="summary"><?= $this->e($series['summary']) ?></p><?php endif; ?>
  </header>
  <?php if ($loggedIn && $isOwner): ?><p><a href="/series/edit/<?= $this->e($series['slug']) ?>"><?= \App\Lang::t('series.edit') ?></a><span class="chapter-meta"> | <?= \App\Lang::t('series.membership_label') ?>: <?= $this->e(\App\Lang::t('series.m_' . $series['membership'])) ?><?= $series['membership'] === 'moderated' ? \App\Lang::t('series.moderated_note') : '' ?></span></p><?php endif; ?>
  <ol class="series-items">
    <?php foreach ($items as $n => $it): ?>
      <li>
        <span class="ch-num"><?= \App\Features\Reader\Roman::numeral($n + 1) ?></span>
        <div class="series-item">
        <a class="story-title" href="/story/view/<?= $this->e($it['slug']) ?>"><?= $this->e($it['title']) ?></a>
        <span class="meta">
          <?= \App\Lang::t('series.chapters_count', ['n' => number_format((int) $it['chapter_count'])]) ?>
          · <?= number_format((int) $it['word_count']) ?> <?= \App\Lang::t('story.words') ?>
          · <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $it['updated_at'], 0, 10))]) ?>
        </span>
        <?php if ((int) $it['confirmed'] === 0): ?><span class="badge"><?= \App\Lang::t('series.pending') ?></span><?php endif; ?>
        <?php if ($isOwner || $isAdmin): ?>
          <?php if ((int) $it['confirmed'] === 0): ?>
            <form method="post" action="/series/confirm/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>" class="inline">
              <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
              <button type="submit"><?= \App\Lang::t('series.confirm') ?></button>
            </form>
          <?php endif; ?>
          <form method="post" action="/series/move/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>/up" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit"><?= \App\Lang::t('series.move_up') ?></button>
          </form>
          <form method="post" action="/series/move/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>/down" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit"><?= \App\Lang::t('series.move_down') ?></button>
          </form>
          <form method="post" action="/series/remove/<?= $this->e($series['slug']) ?>/<?= $this->e($it['slug']) ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
          </form>
        <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($loggedIn && !$isOwner && in_array($series['membership'], ['open', 'moderated'], true)): ?>
    <form method="post" action="/series/add/<?= $this->e($series['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('series.story_slug') ?> <input name="story_slug" required maxlength="60" placeholder="<?= $this->e(\App\Lang::t('series.slug_placeholder')) ?>"></label>
      <button type="submit"><?= \App\Lang::t('series.add_yours') ?></button>
    </form>
  <?php endif; ?>
</section>
