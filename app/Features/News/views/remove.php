<?php // app/views/news/remove.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('news.delete_heading') ?></h1>
  <p><?= \App\Lang::t('news.delete_intro', ['title' => $this->e($row['title'])]) ?></p>
  <form method="post" action="/news/delete/<?= (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('news.delete_submit') ?></button>
    <a href="/news/edit/<?= (int) $row['id'] ?>"><?= \App\Lang::t('common.cancel') ?></a>
  </form>
</section>
