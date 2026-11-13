<?php // app/Features/Categories/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('categories.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('categories.help') ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <ul class="admin-rows">
    <?php foreach ($tree as $c): ?>
    <li style="margin-left: <?= (int) $c['depth'] * 1.5 ?>rem">
      <strong><?= $this->e($c['name']) ?></strong>
      <?= (int) $c['locked'] === 1 ? ' ' . \App\Lang::t('categories.locked_tag') : '' ?>
      <span class="chapter-meta"><?= \App\Lang::t('categories.story_count', ['n' => (int) $c['story_count']]) ?></span>
      <div class="queue-actions">
      <form method="post" action="/categories/move/<?= (int) $c['id'] ?>/up" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_up') ?></button>
      </form>
      <form method="post" action="/categories/move/<?= (int) $c['id'] ?>/down" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_down') ?></button>
      </form>
      <a href="/categories/edit/<?= (int) $c['id'] ?>" class="btn"><?= \App\Lang::t('common.edit') ?></a>
      <a href="/categories/delete/<?= (int) $c['id'] ?>" class="btn"><?= \App\Lang::t('common.delete') ?></a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <h2><?= \App\Lang::t('categories.add_heading') ?></h2>
  <form method="post" action="/categories/create">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('categories.name') ?> <input name="name" required maxlength="100"></label>
    <label><?= \App\Lang::t('categories.description') ?> <textarea name="description" rows="2" maxlength="500"></textarea></label>
    <label><?= \App\Lang::t('categories.parent') ?>
      <select name="parent_id">
        <option value=""><?= \App\Lang::t('categories.top_level') ?></option>
        <?php foreach ($all as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $this->e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
</section>
