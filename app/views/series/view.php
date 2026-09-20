<?php // app/views/series/view.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $this->e($series['title']) ?></h1>
  <p class="chapter-meta">
    by <a href="/user/view/<?= $this->e($series['owner_slug']) ?>"><?= $this->e($series['owner_penname']) ?></a>
    | <?= number_format((int) $series['item_count']) ?> works
    | <?= $this->e($series['created_at']) ?>
  </p>
  <?php if ($series['summary'] !== ''): ?><p><?= $this->e($series['summary']) ?></p><?php endif; ?>
  <?php if ($loggedIn && $isOwner): ?><p><a href="/series/edit/<?= $this->e($series['slug']) ?>">Edit series</a></p><?php endif; ?>
  <ol>
    <?php foreach ($items as $it): ?>
      <li>
        <a href="/story/view/<?= $this->e($it['slug']) ?>"><?= $this->e($it['title']) ?></a>
        <span class="chapter-meta">
          (<?= number_format((int) $it['chapter_count']) ?> chapters
          | <?= number_format((int) $it['word_count']) ?> words
          | <?= $this->e($it['updated_at']) ?>)
        </span>
        <?php if ((int) $it['confirmed'] === 0): ?><span class="badge">Pending</span><?php endif; ?>
        <?php if ($isOwner || $isAdmin): ?>
          <?php if ((int) $it['confirmed'] === 0): ?>
            <form method="post" action="/series/confirm/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>" class="inline">
              <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
              <button type="submit">Confirm</button>
            </form>
          <?php endif; ?>
          <form method="post" action="/series/move/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>/up" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit">Move up</button>
          </form>
          <form method="post" action="/series/move/<?= $this->e($series['slug']) ?>/<?= (int) $it['item_id'] ?>/down" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit">Move down</button>
          </form>
          <form method="post" action="/series/remove/<?= $this->e($series['slug']) ?>/<?= $this->e($it['slug']) ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit">Remove</button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($loggedIn && !$isOwner && in_array($series['membership'], ['open', 'moderated'], true)): ?>
    <form method="post" action="/series/add/<?= $this->e($series['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label>Your story slug <input name="story_slug" required maxlength="60" placeholder="story-slug"></label>
      <button type="submit">Add your story</button>
    </form>
  <?php endif; ?>
</section>
