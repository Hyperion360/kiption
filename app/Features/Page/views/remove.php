<?php // app/views/page/remove.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('page.delete_heading') ?></h1>
  <?php if ($protected): ?>
    <p class="error"><?= \App\Lang::t('page.err_protected') ?></p>
  <?php else: ?>
    <p><?= \App\Lang::t('page.delete_intro', ['title' => $this->e($row['title'])]) ?></p>
    <form method="post" action="/page/delete/<?= $this->e($row['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit"><?= \App\Lang::t('page.delete_submit') ?></button>
      <a href="/page/edit/<?= $this->e($row['slug']) ?>"><?= \App\Lang::t('common.cancel') ?></a>
    </form>
  <?php endif; ?>
</section>
