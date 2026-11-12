<?php // app/Features/Characters/views/delete.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('characters.delete_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p><?= \App\Lang::t('characters.delete_confirm', ['name' => $this->e($character['name'])]) ?></p>
  <form method="post" action="/characters/delete/<?= (int) $character['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($stories > 0): ?>
    <p class="notice"><?= \App\Lang::t('characters.err_in_use', ['n' => $stories]) ?></p>
    <label class="inline"><input type="checkbox" name="remove" value="1"> <?= \App\Lang::t('characters.remove_label') ?></label>
    <?php endif; ?>
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
    <a href="/characters"><?= \App\Lang::t('taxonomy.cancel') ?></a>
  </form>
</section>
