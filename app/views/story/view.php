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
  <h2 id="reviews">Reviews (<?= number_format((int) $review_count) ?>)</h2>
  <?php if ($reviews === []): ?><p class="chapter-meta">None yet.</p>
  <?php else: ?>
    <?php foreach ($reviews as $r): ?>
      <article class="review">
        <p class="chapter-meta"><?= $this->e($r['penname'] ?? $r['guest_name'] ?? 'Anonymous') ?>
          <?= $r['rating'] !== null ? '| ' . (int) $r['rating'] . '/10' : '' ?> | <?= $this->e($r['created_at']) ?></p>
        <div class="prose"><?= \App\Markdown::render($r['body']) ?></div>
        <?php if (!empty($r['replies'])): ?>
          <?php foreach ($r['replies'] as $rep): ?>
            <blockquote class="review-reply">
              <p class="chapter-meta"><?= $this->e($rep['penname'] ?? $rep['guest_name'] ?? 'Anonymous') ?>
                <?= $rep['is_author_reply'] ? '| author' : '' ?></p>
              <div class="prose"><?= \App\Markdown::render($rep['body']) ?></div>
            </blockquote>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($csrf)): ?>
          <form method="post" action="/review/reply/<?= (int) $r['id'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <input name="body" required maxlength="5000" placeholder="Reply to this review" aria-label="Reply to this review">
            <button type="submit">Reply</button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if ($review_count > count($reviews)): ?><p class="chapter-meta">Showing the 50 most recent reviews.</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($csrf)): ?>
    <form method="post" action="/review/add/<?= $this->e($story['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label>Review <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <label>Rating (0-10, optional) <input name="rating" inputmode="numeric" maxlength="2"></label>
      <button type="submit">Post review</button>
    </form>
  <?php else: ?>
    <form method="post" action="/review/add/<?= $this->e($story['slug']) ?>">
      <label>Name <input name="guest_name" required maxlength="40"></label>
      <label>Review <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <label>Rating (0-10, optional) <input name="rating" inputmode="numeric" maxlength="2"></label>
      <button type="submit">Post review as guest</button>
    </form>
  <?php endif; ?>
</article>
