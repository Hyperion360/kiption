<?php // app/Features/Tags/views/edit.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.edit_tag_heading') ?></h1>
  <p class="chapter-meta"><?= $this->e($tag['type_name']) ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" class="form-card" action="/tags/update/<?= (int) $tag['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60" value="<?= $this->e($tag['name']) ?>"></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <p><a href="/tags"><?= \App\Lang::t('taxonomy.back') ?></a></p>
</section>
