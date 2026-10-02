<?php // app/views/adminmembers/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('adminmembers.heading') ?></h1>
<form method="get" action="/adminmembers" class="inline">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('adminmembers.search_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('adminmembers.search_aria')) ?>">
  <button type="submit"><?= \App\Lang::t('adminmembers.search') ?></button>
</form>
<table>
  <thead><tr><th scope="col"><?= \App\Lang::t('adminmembers.column_member') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_email') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_role') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_status') ?></th></tr></thead>
  <tbody>
<?php foreach ($rows as $m): ?>
<?php
    $isSelf = (int) $m['id'] === $me;
    $penname = $m['penname'] === null ? \App\Lang::t('adminmembers.no_penname') : (string) $m['penname'];
?>
    <tr>
      <td>
        <?= $this->e($penname) ?><?= $isSelf ? ' ' . \App\Lang::t('adminmembers.you') : '' ?>
        <?php if ($m['profile_slug'] !== null): ?>
          <a href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= \App\Lang::t('adminmembers.profile') ?></a>
        <?php endif; ?>
      </td>
      <td><?= $this->e($m['email']) ?></td>
      <td>
        <span class="badge"><?= $this->e($m['role']) ?></span>
        <?php foreach ($roles as $r): if ($r !== $m['role']): ?>
        <form method="post" action="/adminmembers/role/<?= (int) $m['id'] ?>/<?= $this->e($r) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"<?= $isSelf ? ' onclick="return confirm(\'' . \App\Lang::t('adminmembers.demote_confirm') . '\')"' : '' ?>><?= $this->e($r) ?></button>
        </form>
        <?php endif; endforeach; ?>
      </td>
      <td>
        <?php if ((int) $m['is_locked'] === 1): ?><span class="badge"><?= \App\Lang::t('adminmembers.locked') ?></span><?php endif; ?>
        <?php if ($m['email_verified_at'] === null): ?><span class="badge"><?= \App\Lang::t('adminmembers.unverified') ?></span><?php endif; ?>
        <?php if ($m['approved_at'] === null): ?><span class="badge"><?= \App\Lang::t('adminmembers.unapproved') ?></span><?php endif; ?>
        <?php if ((int) $m['is_locked'] === 0 && $m['email_verified_at'] !== null && $m['approved_at'] !== null): ?><span class="chapter-meta"><?= \App\Lang::t('adminmembers.active') ?></span><?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
    <tr><td colspan="4" class="meta"><?= \App\Lang::t('adminmembers.no_match') ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php
$qs = $q === '' ? '' : http_build_query(['q' => $q]) . '&';
if ($page > 1 || !empty($hasMore)): ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
<?php if (!empty($hasMore)): ?><a class="pager-next" href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
