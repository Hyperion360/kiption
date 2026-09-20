<?php // app/views/news/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('news.new_post') : \App\Lang::t('news.edit_post') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/news/create' : '/news/update/' . (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="255" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.body') ?> <textarea name="body" rows="12" required placeholder="<?= $this->e(\App\Lang::t('news.body_placeholder')) ?>"><?= $this->e($row['body'] ?? '') ?></textarea></label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
</section>
