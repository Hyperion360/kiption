<?php // app/views/images/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('images.heading') ?></h1>
<p class="meta"><?= $count === 1 ? \App\Lang::t('images.one_file') : \App\Lang::t('images.files', ['n' => $count]) ?>, <?= $this->e($totalHuman) ?> <?= \App\Lang::t('images.total') ?>.</p>
<form method="post" action="/images/upload" enctype="multipart/form-data" class="inline">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="file" name="file" id="file" accept="image/png,image/jpeg,image/webp,image/gif" aria-label="<?= $this->e(\App\Lang::t('images.upload_aria')) ?>">
  <button type="submit"><?= \App\Lang::t('images.upload') ?></button>
</form>
<p class="meta"><?= \App\Lang::t('images.help') ?></p>
<table>
  <thead><tr><th scope="col"><?= \App\Lang::t('images.column_file') ?></th><th scope="col"><?= \App\Lang::t('images.column_size') ?></th><th scope="col"><?= \App\Lang::t('images.column_actions') ?></th></tr></thead>
  <tbody>
<?php foreach ($files as $f): ?>
    <tr>
      <td><code><?= $this->e($f['name']) ?></code></td>
      <td><?= $this->e($f['human']) ?></td>
      <td>
        <?php if ($f['inUse']): ?>
          <span class="badge"><?= \App\Lang::t('images.in_use') ?></span>
        <?php else: ?>
        <form method="post" action="/images/delete" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <input type="hidden" name="name" value="<?= $this->e($f['name']) ?>">
          <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($files === []): ?>
    <tr><td colspan="3" class="meta"><?= \App\Lang::t('images.none') ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
