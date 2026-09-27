<?php // app/views/challenges/view.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $this->e($challenge['title']) ?></h1>
  <p class="chapter-meta">
    <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($challenge['owner_slug']) ?>"><?= $this->e($challenge['owner_penname']) ?></a>
    | <?= \App\Lang::t('common.works_count', ['n' => number_format((int) $challenge['item_count'])]) ?>
    | <?= \App\Lang::t('challenges.m_' . $challenge['membership']) ?>
    | <?= $this->e(substr((string) $challenge['created_at'], 0, 10)) ?>
  </p>
  <?php if ($challenge['summary'] !== ''): ?><p><?= $this->e($challenge['summary']) ?></p><?php endif; ?>
  <?php if ($loggedIn && $isOwner): ?><p><a href="/challenges/edit/<?= $this->e($challenge['slug']) ?>"><?= \App\Lang::t('challenges.edit') ?></a></p><?php endif; ?>

  <h2><?= \App\Lang::t('challenges.prompts_heading') ?></h2>
  <?php if ($challenge['prompts'] === []): ?>
    <p class="chapter-meta"><?= \App\Lang::t('challenges.prompts_empty') ?></p>
  <?php else: ?>
  <ol>
    <?php foreach ($challenge['prompts'] as $p): ?>
      <li class="prose"><?= \App\Markdown::render((string) $p['text']) /* markdown at rest; raw HTML cannot be stored */ ?></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <h2><?= \App\Lang::t('challenges.items_heading') ?></h2>
  <?php if ($items === []): ?>
    <p class="chapter-meta"><?= \App\Lang::t('challenges.no_stories') ?></p>
  <?php else: ?>
  <ol>
    <?php foreach ($items as $it): ?>
      <li>
        <a href="/story/view/<?= $this->e($it['slug']) ?>"><?= $this->e($it['title']) ?></a>
        <span class="chapter-meta">
          (<?= \App\Lang::t('challenges.chapters_count', ['n' => number_format((int) $it['chapter_count'])]) ?>
          | <?= number_format((int) $it['word_count']) ?> <?= \App\Lang::t('story.words') ?>
          | <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $it['updated_at'], 0, 10))]) ?>)
        </span>
        <?php if ((int) $it['confirmed'] === 0): ?><span class="badge"><?= \App\Lang::t('challenges.pending') ?></span><?php endif; ?>
        <?php if ($isOwner || $isAdmin): ?>
          <?php if ((int) $it['confirmed'] === 0): ?>
            <form method="post" action="/challenges/confirm/<?= $this->e($challenge['slug']) ?>/<?= (int) $it['item_id'] ?>" class="inline">
              <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
              <button type="submit"><?= \App\Lang::t('challenges.confirm') ?></button>
            </form>
          <?php endif; ?>
          <form method="post" action="/challenges/remove/<?= $this->e($challenge['slug']) ?>/<?= $this->e($it['slug']) ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>

  <?php if ($loggedIn && in_array($challenge['membership'], ['open', 'moderated'], true)): ?>
    <form method="post" action="/challenges/join/<?= $this->e($challenge['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('challenges.story_slug') ?> <input name="story_slug" required maxlength="60" placeholder="<?= $this->e(\App\Lang::t('challenges.slug_placeholder')) ?>"></label>
      <button type="submit"><?= \App\Lang::t('challenges.add_yours') ?></button>
    </form>
  <?php endif; ?>
</section>
