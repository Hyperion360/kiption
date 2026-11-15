<?php // app/Features/Tagqueue/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagqueue.heading') ?></h1>
  <p class="admin-help"><?= \App\Lang::t('tagqueue.help') ?></p>
  <?php if (($groups ?? []) === []): ?>
  <p class="empty-state"><?= \App\Lang::t('tagqueue.empty') ?></p>
  <?php else: ?>
  <?php foreach ($groups as $g): ?>
  <section class="settings-section">
    <h2 class="section-label"><?= $this->e($g['name']) ?> <span class="chapter-meta"><?= $this->e($g['type']) ?> · <?= \App\Lang::t('tagqueue.stories_count', ['n' => $g['count']]) ?></span></h2>
    <p class="chapter-meta"><?= \App\Lang::t('tagqueue.in_stories') ?> <?= implode(', ', array_map(fn (string $t): string => $this->e($t), $g['titles'])) ?></p>
    <form method="post" action="/tagqueue/approve" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="type_id" value="<?= (int) $g['typeId'] ?>">
      <input type="hidden" name="name" value="<?= $this->e($g['name']) ?>">
      <button type="submit" class="btn-primary"><?= \App\Lang::t('tagqueue.approve') ?></button>
    </form>
    <form method="post" action="/tagqueue/merge" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="type_id" value="<?= (int) $g['typeId'] ?>">
      <input type="hidden" name="name" value="<?= $this->e($g['name']) ?>">
      <label><?= \App\Lang::t('tagqueue.merge') ?>
        <select name="target_id">
          <option value=""><?= \App\Lang::t('tagqueue.pick_existing') ?></option>
          <?php foreach ($g['tags'] as $t): ?>
          <option value="<?= (int) $t['id'] ?>"><?= $this->e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn"><?= \App\Lang::t('tagqueue.merge_go') ?></button>
    </form>
    <form method="post" action="/tagqueue/reject" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="type_id" value="<?= (int) $g['typeId'] ?>">
      <input type="hidden" name="name" value="<?= $this->e($g['name']) ?>">
      <button type="submit" class="btn-quiet"><?= \App\Lang::t('tagqueue.reject') ?></button>
    </form>
  </section>
  <?php endforeach; ?>
  <?php endif; ?>
</section>
