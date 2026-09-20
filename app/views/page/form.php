<?php // app/views/page/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('page.new') : \App\Lang::t('page.edit') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/page/create' : '/page/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($row === null): ?>
      <label><?= \App\Lang::t('page.slug') ?> <input name="slug" required placeholder="<?= $this->e(\App\Lang::t('page.slug_placeholder')) ?>" pattern="[a-z0-9-]+"></label>
    <?php else: ?>
      <label><?= \App\Lang::t('page.slug') ?> <input value="<?= $this->e($row['slug']) ?>" readonly aria-readonly="true"></label>
    <?php endif; ?>
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="255" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.body') ?> <textarea name="body" rows="12" placeholder="<?= $this->e(\App\Lang::t('page.body_placeholder')) ?>"><?= $this->e($row['body'] ?? '') ?></textarea></label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
</section>
