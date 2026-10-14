<?php // app/Features/Review/views/chapter.php ?>
<?php // The chapter comment thread (/review/chapter/{slug}/{n}): the same
     // markup vocabulary as the story page's review section (view.php
     // 200-265), on its own page so chapter comments stay a separate
     // surface from the story's rating-review feed. chapterReviews()
     // returns roots first (newest first) then the replies; bucket the
     // replies onto their root like StoryController's repliesByRoot fold.
     $this->layout('layout');
$repliesByRoot = [];
foreach ($rows as $r) {
    if ($r['parent_id'] !== null) $repliesByRoot[(int) $r['parent_id']][] = $r;
}
$roots = array_values(array_filter($rows, static fn (array $r): bool => $r['parent_id'] === null)); ?>
<section class="story-reviews" aria-labelledby="chapter-comments">
  <p class="chapter-meta"><a href="/story/view/<?= $this->e($slug) ?>"><?= \App\Lang::t('reader.back_to_story') ?></a></p>
  <h2 id="chapter-comments"><?= $this->e($title) ?></h2>
  <?php if ($roots === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?></p>
  <?php else: ?>
    <?php foreach ($roots as $r): ?>
      <article class="review">
        <?php /* The site's meta convention: middle dots, a short date (the raw
           ISO stamp stays in the datetime attribute for machines). */ ?>
        <p class="chapter-meta"><?= $this->e($r['penname'] ?? $r['guest_name'] ?? \App\Lang::t('common.anonymous')) ?><?= $r['rating'] !== null ? ' · ' . (int) $r['rating'] . '/10' : '' ?> · <time datetime="<?= $this->e($r['created_at']) ?>"><?= $this->e(date('M j, Y', strtotime((string) $r['created_at']))) ?></time></p>
        <div class="prose"><?= \App\Markdown::render($r['body'] ?? '') ?></div>
        <?php foreach ($repliesByRoot[(int) $r['id']] ?? [] as $rep): ?>
            <blockquote class="review-reply">
              <p class="chapter-meta"><?= $this->e($rep['penname'] ?? $rep['guest_name'] ?? \App\Lang::t('common.anonymous')) ?><?= (int) $rep['user_id'] === (int) $story['author_id'] ? ' · ' . \App\Lang::t('story.author_reply') : '' ?></p>
              <div class="prose"><?= \App\Markdown::render($rep['body'] ?? '') ?></div>
              <?php if (!empty($csrf)): ?>
                <div class="review-tools"><details class="report-disclosure"><summary><?= \App\Lang::t('story.report_short') ?></summary>
                <form method="post" action="/report/review/<?= (int) $rep['id'] ?>" class="inline">
                  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
                  <input name="reason" required maxlength="500" placeholder="<?= $this->e(\App\Lang::t('story.report_reply_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.report_reply_placeholder')) ?>">
                  <button type="submit"><?= \App\Lang::t('story.report_short') ?></button>
                </form></details></div>
              <?php endif; ?>
            </blockquote>
        <?php endforeach; ?>
        <?php /* Reply and Report fold into disclosures (native details, no
           script): the story page's pattern, posting at the EXISTING reply
           route so a reply lands in whatever thread owns its root. */ ?>
        <?php if (!empty($csrf)): ?>
          <div class="review-tools">
          <details class="report-disclosure"><summary><?= \App\Lang::t('story.reply') ?></summary>
          <form method="post" action="/review/reply/<?= (int) $r['id'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <input name="body" required maxlength="5000" placeholder="<?= $this->e(\App\Lang::t('story.reply_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.reply_placeholder')) ?>">
            <button type="submit"><?= \App\Lang::t('story.reply') ?></button>
          </form></details>
          <details class="report-disclosure"><summary><?= \App\Lang::t('story.report_short') ?></summary>
          <form method="post" action="/report/review/<?= (int) $r['id'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <input name="reason" required maxlength="500" placeholder="<?= $this->e(\App\Lang::t('story.report_review_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('story.report_review_placeholder')) ?>">
            <button type="submit"><?= \App\Lang::t('story.report_short') ?></button>
          </form></details>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if (!empty($csrf)): ?>
    <form class="form-card review-form" method="post" action="/review/addchapter/<?= $this->e($slug) ?>/<?= (int) $position ?>">
      <p class="form-card-title"><?= \App\Lang::t('review.post_comment') ?></p>
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('review.comment_label') ?> <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <button type="submit" class="btn-primary"><?= \App\Lang::t('review.post_comment') ?></button>
    </form>
  <?php else: ?>
    <form class="form-card review-form" method="post" action="/review/addchapter/<?= $this->e($slug) ?>/<?= (int) $position ?>">
      <p class="form-card-title"><?= \App\Lang::t('review.post_comment') ?></p>
      <label><?= \App\Lang::t('story.name') ?> <input name="guest_name" required maxlength="40"></label>
      <label><?= \App\Lang::t('review.comment_label') ?> <textarea name="body" rows="4" required maxlength="5000"></textarea></label>
      <button type="submit" class="btn-primary"><?= \App\Lang::t('review.post_comment') ?></button>
    </form>
  <?php endif; ?>
</section>
