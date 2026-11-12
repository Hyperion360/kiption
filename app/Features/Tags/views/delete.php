<?php // app/Features/Tags/views/delete.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.delete_tag_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p><?= \App\Lang::t('tagsadmin.delete_tag_confirm', ['name' => $this->e($tag['name'])]) ?></p>
  <form method="post" action="/tags/delete/<?= (int) $tag['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($stories > 0): ?>
    <p class="notice"><?= \App\Lang::t('tagsadmin.err_in_use', ['n' => $stories]) ?></p>
    <label class="inline"><input type="checkbox" name="remove" value="1"> <?= \App\Lang::t('tagsadmin.remove_label') ?></label>
    <?php endif; ?>
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
    <a href="/tags"><?= \App\Lang::t('taxonomy.cancel') ?></a>
  </form>
</section>
