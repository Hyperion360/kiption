<?php // app/Features/Characters/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('characters.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('characters.help') ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if ($characters === []): ?><p class="chapter-meta"><?= \App\Lang::t('characters.none') ?></p><?php else: ?>
  <ul>
    <?php foreach ($characters as $c): ?>
    <li>
      <?= $this->e($c['name']) ?>
      <span class="chapter-meta"><?= $c['category_name'] === null ? \App\Lang::t('characters.any_category') : $this->e($c['category_name']) ?></span>
      <span class="chapter-meta"><?= \App\Lang::t('characters.story_count', ['n' => (int) $c['story_count']]) ?></span>
      <a href="/characters/edit/<?= (int) $c['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
      <a href="/characters/delete/<?= (int) $c['id'] ?>"><?= \App\Lang::t('common.delete') ?></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('characters.add_heading') ?></h2>
  <form method="post" action="/characters/create">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="80"></label>
    <label><?= \App\Lang::t('characters.category') ?>
      <select name="category_id">
        <option value="0"><?= \App\Lang::t('characters.any_category') ?></option>
        <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>"><?= $this->e($cat['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('characters.description') ?> <input name="description" maxlength="500"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
</section>
