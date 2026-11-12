<?php // app/Features/Tags/views/edittype.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.edit_type_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/tags/updatetype/<?= (int) $type['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60" value="<?= $this->e($type['name']) ?>"></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <p><a href="/tags"><?= \App\Lang::t('taxonomy.back') ?></a></p>
</section>
