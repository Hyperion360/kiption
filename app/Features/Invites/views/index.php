<?php // app/Features/Invites/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('invites.heading') ?></h1>
  <form method="post" action="/invites/create" class="inline">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('invites.create') ?></button>
  </form>
  <?php if ($rows === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('invites.none') ?></p>
  <?php else: ?>
  <table>
    <tr>
      <th scope="col"><?= \App\Lang::t('invites.code') ?></th>
      <th scope="col"><?= \App\Lang::t('invites.created_by') ?></th>
      <th scope="col"><?= \App\Lang::t('invites.created_at') ?></th>
      <th scope="col"><?= \App\Lang::t('invites.used_by') ?></th>
      <th scope="col"><?= \App\Lang::t('invites.used_at') ?></th>
      <th scope="col"><?= \App\Lang::t('invites.actions') ?></th>
    </tr>
    <?php foreach ($rows as $row): ?>
    <tr>
      <td><code><?= $this->e($row['code']) ?></code><?php if ($row['used_by'] !== null): ?> <span class="chapter-meta">(<?= \App\Lang::t('invites.used') ?>)</span><?php endif; ?></td>
      <td><?= $this->e($row['created_by_name']) ?></td>
      <td><time datetime="<?= $this->e($row['created_at']) ?>"><?= $this->e(date('M j', strtotime((string) $row['created_at']))) ?></time></td>
      <td><?= $row['used_by_name'] !== null ? $this->e($row['used_by_name']) : '' ?></td>
      <td><?php if ($row['used_at'] !== null): ?><time datetime="<?= $this->e($row['used_at']) ?>"><?= $this->e(date('M j', strtotime((string) $row['used_at']))) ?></time><?php endif; ?></td>
      <td>
        <?php if ($row['used_by'] === null): ?>
        <form method="post" action="/invites/revoke/<?= (int) $row['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('invites.revoke') ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
