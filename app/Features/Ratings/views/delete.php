<?php // app/Features/Ratings/views/delete.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('ratings.delete_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p><?= \App\Lang::t('ratings.delete_confirm', ['name' => $this->e($row['label'])]) ?></p>
  <?php if ($stories > 0): ?><p class="notice"><?= \App\Lang::t('ratings.err_in_use', ['n' => $stories]) ?></p><?php endif; ?>
  <form method="post" action="/ratings/delete/<?= (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($stories > 0 && $others !== []): ?>
    <label><?= \App\Lang::t('ratings.move_to') ?>
      <select name="move_to" required>
        <option value=""><?= \App\Lang::t('ratings.choose') ?></option>
        <?php foreach ($others as $o): ?><option value="<?= (int) $o['id'] ?>"><?= $this->e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= \App\Lang::t('ratings.move_and_delete') ?></button>
    <?php elseif ($stories === 0): ?>
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
    <?php endif; ?>
    <a href="/ratings"><?= \App\Lang::t('taxonomy.cancel') ?></a>
  </form>
</section>
