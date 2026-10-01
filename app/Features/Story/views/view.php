<?php // app/views/story/view.php ?>
<?php // C7 (frames M1/T2/D3): hero grid, stats, Roman-numeral chapter list.
     // The engagement row and the reviews section keep their exact structure
     // (every id, form action, and pinned class survives); only wrappers
     // restyle. Guest renders carry none of the member progress markup, so
     // cached bytes stay reader-neutral.
     $this->layout('layout');
     $progress = $progress ?? null;
     $last = $progress['last_position'] ?? null;
     $readPct = (int) ($progress['read_pct'] ?? 0);
     $hereTitle = '';
     if ($last !== null) {
         foreach ($chapters as $c) {
             if ((int) $c['position'] === (int) $last) { $hereTitle = (string) $c['title']; break; }
         }
     }
     // category_names is a nullable ", "-joined GROUP_CONCAT STRING (never an
     // array); re-separate for the comp's middle dots, status joins only when
     // categories exist so an uncategorized story never leads with a dot.
     $cats = str_replace(', ', ' · ', (string) ($story['category_names'] ?? '')); ?>
<article class="story-page">
  <div class="story-hero">
    <aside class="story-cover">
      <?php if (!empty($story['cover_path'])): ?>
        <img class="cover" src="<?= $this->e($story['cover_path']) ?>" alt="">
      <?php else: /* typographic cover: 2:3 brand-green card, comp T2/D3 */ ?>
        <div class="cover-card"><span class="cover-kicker"><?= \App\Lang::t('story.cover_kicker') ?></span>
          <span class="cover-title"><?= $this->e($story['title']) ?></span>
          <span class="cover-author"><?= $this->e($story['penname']) ?></span></div>
      <?php endif; ?>
      <?php if ($last !== null): ?>
      <a class="continue-cta" href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $last ?>">
        <span class="continue-label"><?= \App\Lang::t('reader.continue') ?></span>
        <span class="continue-sub"><?= \App\Lang::t('reader.continue_sub', ['roman' => \App\Features\Reader\Roman::numeral((int) $last), 'title' => $this->e($hereTitle !== '' ? $hereTitle : \App\Lang::t('story.chapter_n', ['n' => (int) $last])), 'pct' => $readPct]) ?></span></a>
      <?php endif; ?>
    </aside>
    <div class="story-info">
      <p class="eyebrow"><?= $cats !== '' ? $this->e($cats) . ' · ' : '' ?><?= \App\Lang::t($story['completed'] ? 'story.complete' : 'story.wip') ?></p>
      <h1><?= $this->e($story['title']) ?></h1>
      <p class="byline"><?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($story['profile_slug']) ?>"><?= $this->e($story['penname']) ?></a><?php foreach ($coauthors as $co): ?>, <a href="/user/view/<?= $this->e($co['p']) ?>"><?= $this->e($co['n']) ?></a><?php endforeach; ?></p>
      <?php /* one markup, two presentations: the meta line shows below 1024px, the stats dl from 1024px up */ ?>
      <p class="meta meta-line"><?= $this->e($story['rating_label']) ?> · <?= number_format((int) $story['word_count']) ?> <?= \App\Lang::t('story.words') ?> · <?= \App\Lang::t('reader.chapters_n', ['n' => count($chapters)]) ?><?php if ($progress !== null && $progress['minutes_left'] !== null): ?> · <?= \App\Lang::t('reader.about_left', ['min' => (int) $progress['minutes_left']]) ?><?php endif; ?>
        <?php if ((int) $story['is_adult'] === 1): ?><span class="badge"><?= \App\Lang::t('story.adult') ?></span><?php endif; ?>
        <?php if ((int) $story['is_restricted'] === 1): ?><span class="badge"><?= \App\Lang::t('story.registered_only') ?></span><?php endif; ?>
        <?php if ($story['round_robin']): ?><span class="badge"><?= \App\Lang::t('story.round_robin') ?></span><?php endif; ?>
        <?php if (($story['language'] ?? '') !== ''): ?><span class="badge"><?= $this->e($story['language']) ?></span><?php endif; ?></p>
      <dl class="story-stats">
        <dt><?= \App\Lang::t('story.rating') ?></dt><dd><?= $this->e($story['rating_label']) ?></dd>
        <dt><?= \App\Lang::t('story.words') ?></dt><dd><?= number_format((int) $story['word_count']) ?></dd>
        <?php if ($progress !== null && $progress['minutes_left'] !== null): ?><dt><?= \App\Lang::t('reader.time_left') ?></dt><dd><?= \App\Lang::t('reader.min_left', ['n' => (int) $progress['minutes_left']]) ?></dd><?php endif; ?>
        <dt><?= \App\Lang::t('story.kudos_label') ?></dt><dd><?= number_format((int) $kudos_count) ?></dd>
        <dt><?= \App\Lang::t('story.reviews_heading') ?></dt><dd><?= number_format((int) $review_count) ?></dd>
      </dl>
      <p class="summary"><?= $this->e($story['summary']) ?></p>
      <?php if (($story['gift_to'] ?? '') !== ''): ?>
      <p class="meta"><?= \App\Lang::t('story.gift_line', ['name' => $this->e($story['gift_to'])]) ?></p>
      <?php endif; ?>
      <?php if (($story['crosspost_url'] ?? '') !== ''): ?>
      <p class="meta"><?= \App\Lang::t('story.crossposted_from') ?> <a href="<?= $this->e($story['crosspost_url']) ?>" rel="nofollow"><?= \App\Lang::t('story.crosspost_original') ?></a>.</p>
      <?php endif; ?>
      <?php if ($series !== []): ?>
      <p class="meta"><?= \App\Lang::t('story.series_label') ?>
        <?php foreach ($series as $i => $ser): ?><?= $i > 0 ? ', ' : '' ?><a href="/series/view/<?= $this->e($ser['s']) ?>"><?= $this->e($ser['t']) ?></a><?php endforeach; ?>
      </p>
      <?php endif; ?>
      <?php if ($tags !== []): ?>
      <div class="tag-chips"><?php foreach ($tags as $typeName => $names): ?><span class="badge"><?= $this->e($typeName) ?>: <?= $this->e(implode(', ', $names)) ?></span><?php endforeach; ?></div>
      <?php endif; ?>
      <div class="engagement-bar">
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
    <?php if (\App\Features::on('lists')): ?><span><a href="/lists"><?= \App\Lang::t('story.lists_link') ?></a></span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (($story['notes'] ?? '') !== ''): ?>
    <div class="chapter-meta"><?= \App\Markdown::render($story['notes']) ?></div>
  <?php endif; ?>
  <section class="chapters" aria-labelledby="chapters-h">
    <div class="section-head"><h2 id="chapters-h"><?= \App\Lang::t('story.chapters') ?></h2>
      <?php if (\App\Features::on('exports')): ?>
      <nav class="section-links">
        <a href="/story/whole/<?= $this->e($story['slug']) ?>"><?= \App\Lang::t('story.whole_link') ?></a>
        | <a href="/story/download/<?= $this->e($story['slug']) ?>/html"><?= \App\Lang::t('story.download_html') ?></a>
        | <a href="/story/download/<?= $this->e($story['slug']) ?>/epub"><?= \App\Lang::t('story.download_epub') ?></a>
      </nav>
      <?php endif; ?>
    </div>
    <ol class="chapter-list">
      <?php foreach ($chapters as $c): $cur = $last !== null && (int) $c['position'] === (int) $last; ?>
      <li class="<?= $cur ? 'is-current' : ($last !== null && (int) $c['position'] < (int) $last ? 'is-read' : '') ?>">
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>"<?= $cur ? ' aria-current="page"' : '' ?>>
          <span class="ch-num"><?= \App\Features\Reader\Roman::numeral((int) $c['position']) ?></span>
          <span class="ch-title"><?= $this->e($c['title']) ?><?php if ($cur): ?> <span class="you-are-here"><?= \App\Lang::t('reader.you_are_here', ['pct' => $readPct]) ?></span><?php endif; ?></span>
          <span class="ch-meta"><?= number_format((int) $c['word_count']) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ol>
  </section>
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
