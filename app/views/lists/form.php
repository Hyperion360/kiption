<?php // app/views/lists/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('lists.new') : \App\Lang::t('lists.edit') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/lists/create' : '/lists/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="120" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="500" rows="3"><?= $this->e($row['summary'] ?? '') ?></textarea></label>
    <label><input type="checkbox" name="is_public" value="1"<?= (int) ($row['is_public'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('lists.public_label') ?></label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
  <?php if ($row !== null): ?>
  <h2><?= \App\Lang::t('lists.items_heading') ?></h2>
  <ol>
    <?php foreach ($items as $it): ?>
      <li>
        <a href="/story/view/<?= $this->e($it['slug']) ?>"><?= $this->e($it['title']) ?></a>
        <?php if ((string) ($it['note'] ?? '') !== ''): ?><span class="chapter-meta"> - <?= $this->e($it['note']) ?></span><?php endif; ?>
        <form method="post" action="/lists/move/<?= $this->e($row['slug']) ?>/<?= (int) $it['id'] ?>/up" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('lists.move_up') ?></button>
        </form>
        <form method="post" action="/lists/move/<?= $this->e($row['slug']) ?>/<?= (int) $it['id'] ?>/down" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('lists.move_down') ?></button>
        </form>
        <form method="post" action="/lists/remove/<?= $this->e($row['slug']) ?>/<?= $this->e($it['slug']) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($items === []): ?><p class="chapter-meta"><?= \App\Lang::t('lists.empty') ?></p><?php endif; ?>
  <form method="post" action="/lists/item/<?= $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('lists.story_slug') ?> <input name="story_slug" required maxlength="60" placeholder="<?= $this->e(\App\Lang::t('lists.slug_placeholder')) ?>"></label>
    <label><?= \App\Lang::t('lists.note') ?> <input name="note" maxlength="500"></label>
    <button type="submit"><?= \App\Lang::t('lists.add_item') ?></button>
  </form>
  <?php endif; ?>
</section>
