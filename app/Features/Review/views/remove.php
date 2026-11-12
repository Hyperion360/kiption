<?php // app/views/review/remove.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('review.delete_heading') ?></h1>
  <p><?= \App\Lang::t('review.delete_intro', ['story' => $this->e($review['title'])]) ?></p>
  <blockquote><?= $this->e(mb_substr((string) $review['body'], 0, 500)) ?></blockquote>
  <form method="post" action="/review/delete/<?= (int) $review['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('review.delete_submit') ?></button>
    <a href="/story/view/<?= $this->e($review['slug']) ?>#reviews"><?= \App\Lang::t('common.cancel') ?></a>
  </form>
</section>
