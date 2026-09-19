<?php // app/views/story/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="chapter-meta">
    by <?= $this->e($story['penname']) ?>
    | <?= $this->e($story['rating_label']) ?>
    <?php if ((int) $story['is_adult'] === 1): ?><span class="badge">Adult</span><?php endif; ?>
    <?= $story['completed'] ? '<span class="badge">Complete</span>' : '<span class="badge">WIP</span>' ?>
    | <?= number_format((int) $story['word_count']) ?> words
    | in <?= $this->e($story['category_names'] ?? 'Uncategorized') ?>
  </p>
  <p><?= $this->e($story['summary']) ?></p>
  <div class="engagement-bar chapter-meta">
    <span>Kudos: <?= number_format((int) $kudos_count) ?></span>
    <?php if ((int) $kudos_by_me === 1): ?>
      <span>You left kudos</span>
    <?php else: ?>
      <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
        <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
        <button type="submit">Leave kudos</button>
      </form>
    <?php endif; ?>
    <span>Favorites: <?= number_format((int) $favorite_count) ?></span>
    <?php if ((int) $favorite_by_me === 1): ?>
      <span>In your favorites</span>
    <?php elseif (!empty($csrf)): ?>
      <form method="post" action="/favorites/toggle/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit">Add to favorites</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($csrf)): ?>
      <?php if ((int) $following_author === 1): ?>
        <span>You follow this author</span>
      <?php else: ?>
        <form method="post" action="/follow/author/<?= (int) $story['author_id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit">Follow author</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($csrf)): ?>
      <form method="post" action="/story/mark/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"><?= $marked_at_me === null ? 'Mark for later' : 'Unmark' ?></button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (($story['notes'] ?? '') !== ''): ?>
    <div class="chapter-meta"><?= \App\Markdown::render($story['notes']) ?></div>
  <?php endif; ?>
  <h2>Chapters</h2>
  <ol>
    <?php foreach ($chapters as $c): ?>
      <li>
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>">
          Chapter <?= (int) $c['position'] ?>: <?= $this->e($c['title']) ?></a>
        <span class="chapter-meta">(<?= number_format((int) $c['word_count']) ?> words)</span>
      </li>
    <?php endforeach; ?>
  </ol>
</article>
