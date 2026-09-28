<?php // app/views/features/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('features.heading') ?></h1>
<p class="chapter-meta"><?= \App\Lang::t('features.note') ?></p>
<table>
  <thead><tr><th scope="col"><?= \App\Lang::t('features.column_feature') ?></th><th scope="col"><?= \App\Lang::t('features.column_description') ?></th><th scope="col"><?= \App\Lang::t('features.column_state') ?></th><th scope="col"><?= \App\Lang::t('features.column_action') ?></th></tr></thead>
  <tbody>
<?php foreach ($features as $key => $f): ?>
    <tr>
      <th scope="row"><?= $this->e($key) ?></th>
      <td><?= \App\Lang::t($f['desc']) ?></td>
      <td><?php if ($f['on']): ?><span class="badge"><?= \App\Lang::t('features.on') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('features.off') ?></span><?php endif; ?></td>
      <td>
        <form method="post" action="/features/toggle/<?= $this->e($key) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= $f['on'] ? \App\Lang::t('features.turn_off') : \App\Lang::t('features.turn_on') ?></button>
        </form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
