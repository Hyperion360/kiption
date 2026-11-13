<?php // app/views/report/remove.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('report.remove_heading') ?></h1>
  <p><?= \App\Lang::t($target['kind'] === 'story' ? 'report.remove_story_intro' : 'report.remove_review_intro', ['title' => $this->e($target['title'])]) ?></p>
  <?php if ($target['excerpt'] !== ''): ?><blockquote><?= $this->e(mb_substr((string) $target['excerpt'], 0, 500)) ?></blockquote><?php endif; ?>
  <?php if ($target['author_email'] === null): ?><p class="chapter-meta"><?= \App\Lang::t('report.no_author_mail') ?></p><?php endif; ?>
  <form method="post" action="/report/takedown/<?= (int) $reportId ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('report.reason_label') ?> <textarea name="reason" rows="4" maxlength="1000"></textarea></label>
    <div class="confirm-actions">
      <a class="btn" href="/queue"><?= \App\Lang::t('common.cancel') ?></a>
      <button type="submit" class="btn-danger"><?= \App\Lang::t('report.remove_submit') ?></button>
    </div>
  </form>
</section>
