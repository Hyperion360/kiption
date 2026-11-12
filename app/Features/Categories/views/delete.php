<?php // app/Features/Categories/views/delete.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('categories.delete_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <p><?= \App\Lang::t('categories.delete_confirm', ['name' => $this->e($row['name'])]) ?></p>
  <?php if ($use['children'] > 0): ?>
  <p class="notice"><?= \App\Lang::t('categories.err_children', ['n' => $use['children']]) ?></p>
  <?php elseif ($use['stories'] > 0 || $use['characters'] > 0): ?>
  <p class="notice"><?= \App\Lang::t('categories.err_in_use', ['n' => $use['stories'], 'c' => $use['characters']]) ?></p>
  <?php endif; ?>
  <form method="post" action="/categories/delete/<?= (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($use['children'] === 0 && ($use['stories'] > 0 || $use['characters'] > 0) && $others !== []): ?>
    <label><?= \App\Lang::t('categories.move_to') ?>
      <select name="move_to" required>
        <option value=""><?= \App\Lang::t('categories.choose') ?></option>
        <?php foreach ($others as $o): ?><option value="<?= (int) $o['id'] ?>"><?= $this->e($o['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= \App\Lang::t('categories.move_and_delete') ?></button>
    <?php elseif ($use['children'] === 0 && $use['stories'] === 0 && $use['characters'] === 0): ?>
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
    <?php endif; ?>
    <a href="/categories"><?= \App\Lang::t('taxonomy.cancel') ?></a>
  </form>
</section>
