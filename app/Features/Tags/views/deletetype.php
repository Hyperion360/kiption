<?php // app/Features/Tags/views/deletetype.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.delete_type_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p><?= \App\Lang::t('tagsadmin.delete_type_confirm', ['name' => $this->e($type['name'])]) ?></p>
  <?php if ($tags > 0): ?>
  <p class="notice"><?= \App\Lang::t('tagsadmin.err_type_in_use', ['n' => $tags]) ?></p>
  <?php else: ?>
  <form method="post" action="/tags/deletetype/<?= (int) $type['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
  </form>
  <?php endif; ?>
  <p><a href="/tags"><?= \App\Lang::t('taxonomy.cancel') ?></a></p>
</section>
