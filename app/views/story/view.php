<?php // app/views/story/view.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($story['title']) ?></h1>
  <p class="chapter-meta">
    <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($story['profile_slug']) ?>"><?= $this->e($story['penname']) ?></a><?php foreach ($coauthors as $co): ?>, <a href="/user/view/<?= $this->e($co['p']) ?>"><?= $this->e($co['n']) ?></a><?php endforeach; ?>
    | <?= $this->e($story['rating_label']) ?>
    <?php if ((int) $story['is_adult'] === 1): ?><span class="badge"><?= \App\Lang::t('story.adult') ?></span><?php endif; ?>
    <?php if ((int) $story['is_restricted'] === 1): ?><span class="badge"><?= \App\Lang::t('story.registered_only') ?></span><?php endif; ?>
    <?php if ($story['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?>
    | <?= number_format((int) $story['word_count']) ?> <?= \App\Lang::t('story.words') ?>
    | <?= \App\Lang::t('story.in') ?> <?= $this->e($story['category_names'] ?? \App\Lang::t('story.uncategorized')) ?>
    <?php if (($story['language'] ?? '') !== ''): ?><span class="badge"><?= $this->e($story['language']) ?></span><?php endif; ?>
  </p>
  <?php if (($story['crosspost_url'] ?? '') !== ''): ?>
  <p class="chapter-meta"><?= \App\Lang::t('story.crossposted_from') ?> <a href="<?= $this->e($story['crosspost_url']) ?>" rel="nofollow"><?= \App\Lang::t('story.crosspost_original') ?></a>.</p>
  <?php endif; ?>
  <?php if ($series !== []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('story.series_label') ?>
    <?php foreach ($series as $i => $ser): ?><?= $i > 0 ? ', ' : '' ?><a href="/series/view/<?= $this->e($ser['s']) ?>"><?= $this->e($ser['t']) ?></a><?php endforeach; ?>
  </p>
  <?php endif; ?>
  <?php if (!empty($story['cover_path'])): ?>
    <img class="cover" src="<?= $this->e($story['cover_path']) ?>" alt="<?= $this->e(\App\Lang::t('story.cover_alt')) ?>">
  <?php endif; ?>
  <p><?= $this->e($story['summary']) ?></p>
  <div class="engagement-bar chapter-meta">
    <span><?= \App\Lang::t('story.kudos_count', ['n' => number_format((int) $kudos_count)]) ?></span>
    <?php if ((int) $kudos_by_me === 1): ?>
      <span><?= \App\Lang::t('story.you_left_kudos') ?></span>
    <?php else: ?>
      <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
        <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
        <button type="submit"><?= \App\Lang::t('story.leave_kudos') ?></button>
      </form>
    <?php endif; ?>
    <span><?= \App\Lang::t('story.favorites_count', ['n' => number_format((int) $favorite_count)]) ?></span>
    <?php if ((int) $favorite_by_me === 1): ?>
      <span><?= \App\Lang::t('story.in_your_favorites') ?></span>
    <?php elseif (!empty($csrf)): ?>
      <form method="post" action="/favorites/toggle/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"><?= \App\Lang::t('story.add_to_favorites') ?></button>
      </form>
    <?php endif; ?>
    <?php if (!empty($csrf)): ?>
      <?php if ((int) $following_author === 1): ?>
        <span><?= \App\Lang::t('story.you_follow_author') ?></span>
      <?php else: ?>
        <form method="post" action="/follow/author/<?= (int) $story['author_id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('story.follow_author') ?></button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($csrf)): ?>
      <?php if (!empty($amCoauthor)): ?>
      <form method="post" action="/coauthor/leave/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"><?= \App\Lang::t('story.leave_coauthor') ?></button>
      </form>
      <?php endif; ?>
      <form method="post" action="/story/mark/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"><?= $marked_at_me === null ? \App\Lang::t('story.mark_for_later') : \App\Lang::t('story.unmark') ?></button>
      </form>
      <form method="post" action="/report/story/<?= $this->e($story['slug']) ?>" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <input name="reason" required maxlength="500" placeholder="<?= $this->e(\App\Lang::t('story.report_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.report_placeholder')) ?>">
        <button type="submit"><?= \App\Lang::t('story.report') ?></button>
      </form>
    <?php endif; ?>
    <?php if (!empty($story['support_url'])): ?>
      <a href="<?= $this->e($story['support_url']) ?>" rel="noopener nofollow"><?= \App\Lang::t('story.support') ?></a>
    <?php endif; ?>
    <span><a href="/lists"><?= \App\Lang::t('story.lists_link') ?></a></span>
  </div>
  <?php if (($story['notes'] ?? '') !== ''): ?>
    <div class="chapter-meta"><?= \App\Markdown::render($story['notes']) ?></div>
  <?php endif; ?>
  <h2><?= \App\Lang::t('story.chapters') ?></h2>
  <p class="chapter-meta">
    <a href="/story/whole/<?= $this->e($story['slug']) ?>"><?= \App\Lang::t('story.whole_link') ?></a>
    | <a href="/story/download/<?= $this->e($story['slug']) ?>/html"><?= \App\Lang::t('story.download_html') ?></a>
    | <a href="/story/download/<?= $this->e($story['slug']) ?>/epub"><?= \App\Lang::t('story.download_epub') ?></a>
  </p>
  <ol>
    <?php foreach ($chapters as $c): ?>
      <li>
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>">
          <?= \App\Lang::t('story.chapter_n', ['n' => (int) $c['position']]) ?>: <?= $this->e($c['title']) ?></a>
        <span class="chapter-meta">(<?= number_format((int) $c['word_count']) ?> <?= \App\Lang::t('story.words') ?>)</span>
      </li>
    <?php endforeach; ?>
  </ol>
  <h2 id="reviews"><?= \App\Lang::t('story.reviews_heading') . ' (' . number_format((int) $review_count) . ')' ?></h2>
  <?php if ($reviews === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?></p>
  <?php else: ?>
    <?php foreach ($reviews as $r): ?>
      <article class="review">
        <p class="chapter-meta"><?= $this->e($r['penname'] ?? $r['guest_name'] ?? \App\Lang::t('common.anonymous')) ?>
          <?= $r['rating'] !== null ? '| ' . (int) $r['rating'] . '/10' : '' ?> | <?= $this->e($r['created_at']) ?></p>
        <div class="prose"><?= \App\Markdown::render($r['body'] ?? '') ?></div>
        <?php if (!empty($r['replies'])): ?>
          <?php foreach ($r['replies'] as $rep): ?>
            <blockquote class="review-reply">
              <p class="chapter-meta"><?= $this->e($rep['penname'] ?? $rep['guest_name'] ?? \App\Lang::t('common.anonymous')) ?>
                <?= $rep['is_author_reply'] ? '| ' . \App\Lang::t('story.author_reply') : '' ?></p>
              <div class="prose"><?= \App\Markdown::render($rep['body'] ?? '') ?></div>
              <?php if (!empty($csrf)): ?>
                <form method="post" action="/report/review/<?= (int) $rep['id'] ?>" class="inline">
                  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
                  <input name="reason" required maxlength="500" placeholder="<?= $this->e(\App\Lang::t('story.report_reply_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.report_reply_placeholder')) ?>">
                  <button type="submit"><?= \App\Lang::t('story.report_short') ?></button>
                </form>
              <?php endif; ?>
            </blockquote>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($csrf)): ?>
          <form method="post" action="/review/reply/<?= (int) $r['id'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <input name="body" required maxlength="5000" placeholder="<?= $this->e(\App\Lang::t('story.reply_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.reply_placeholder')) ?>">
            <button type="submit"><?= \App\Lang::t('story.reply') ?></button>
          </form>
          <form method="post" action="/report/review/<?= (int) $r['id'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <input name="reason" required maxlength="500" placeholder="<?= $this->e(\App\Lang::t('story.report_review_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.report_review_placeholder')) ?>">
            <button type="submit"><?= \App\Lang::t('story.report_short') ?></button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if ($review_count > count($reviews) || $repliesDropped): ?><p class="chapter-meta"><?= \App\Lang::t('story.recent_50') ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($csrf)): ?>
    <form method="post" action="/review/add/<?= $this->e($story['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('story.review_label') ?> <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <label><?= \App\Lang::t('story.rating_optional') ?> <input name="rating" inputmode="numeric" maxlength="2"></label>
      <button type="submit"><?= \App\Lang::t('story.post_review') ?></button>
    </form>
  <?php else: ?>
    <form method="post" action="/review/add/<?= $this->e($story['slug']) ?>">
      <label><?= \App\Lang::t('story.name') ?> <input name="guest_name" required maxlength="40"></label>
      <label><?= \App\Lang::t('story.review_label') ?> <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <label><?= \App\Lang::t('story.rating_optional') ?> <input name="rating" inputmode="numeric" maxlength="2"></label>
      <button type="submit"><?= \App\Lang::t('story.post_review_guest') ?></button>
    </form>
  <?php endif; ?>
</article>
