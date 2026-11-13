<?php // app/views/adminmembers/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('adminmembers.heading') ?></h1>
<form method="get" action="/adminmembers" class="inline">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('adminmembers.search_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('adminmembers.search_aria')) ?>">
  <button type="submit"><?= \App\Lang::t('adminmembers.search') ?></button>
</form>
<table class="member-table">
  <thead><tr><th scope="col"><?= \App\Lang::t('adminmembers.column_member') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_email') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_role') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_status') ?></th></tr></thead>
  <tbody>
<?php foreach ($rows as $m): ?>
<?php
    $isSelf = (int) $m['id'] === $me;
    $penname = $m['penname'] === null ? \App\Lang::t('adminmembers.no_penname') : (string) $m['penname'];
?>
    <tr>
      <th scope="row">
        <?= $this->e($penname) ?><?= $isSelf ? ' ' . \App\Lang::t('adminmembers.you') : '' ?>
        <?php if ($m['profile_slug'] !== null): ?>
          <a href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= \App\Lang::t('adminmembers.profile') ?></a>
        <?php endif; ?>
        <?php $stories = (int) $m['stories']; $open = (int) $m['open_reports']; ?>
        <p class="member-facts">
          <span><?= $m['last_signin'] !== null ? \App\Lang::t('adminmembers.last_signin', ['date' => $this->e(\App\Dates::format((string) $m['last_signin']))]) : \App\Lang::t('adminmembers.no_signin') ?></span>
          <span><?= \App\Lang::t($stories === 1 ? 'adminmembers.stories_one' : 'adminmembers.stories', ['n' => $stories]) ?></span>
          <?php if ($open > 0): ?><span class="ctx-warn"><?= \App\Lang::t($open === 1 ? 'adminmembers.open_reports_one' : 'adminmembers.open_reports', ['n' => $open]) ?></span><?php endif; ?>
        </p>
      </th>
      <td class="member-email"><?= $this->e($m['email']) ?></td>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_role')) ?>">
        <span class="badge"><?= \App\Lang::t('adminmembers.role.' . $m['role']) ?></span>
        <?php if (!$isSelf): ?>
        <div class="member-actions">
        <?php foreach ($roles as $r): if ($r !== $m['role']): ?>
        <form method="post" action="/adminmembers/role/<?= (int) $m['id'] ?>/<?= $this->e($r) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('adminmembers.make.' . $r) ?></button>
        </form>
        <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
      </td>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_status')) ?>">
        <?php $deleted = str_ends_with((string) $m['email'], '@deleted.invalid'); ?>
        <?php if ($deleted): ?><span class="badge"><?= \App\Lang::t('adminmembers.deleted') ?></span><?php endif; ?>
        <?php if ((int) $m['is_locked'] === 1 && !$deleted): ?><span class="badge"><?= \App\Lang::t('adminmembers.locked') ?></span><?php endif; ?>
        <?php if ($m['email_verified_at'] === null): ?><span class="badge"><?= \App\Lang::t('adminmembers.unverified') ?></span><?php endif; ?>
        <?php if ($m['approved_at'] === null): ?><span class="badge"><?= \App\Lang::t('adminmembers.unapproved') ?></span><?php endif; ?>
        <?php if ((int) $m['is_locked'] === 0 && $m['email_verified_at'] !== null && $m['approved_at'] !== null): ?><span class="chapter-meta"><?= \App\Lang::t('adminmembers.active') ?></span><?php endif; ?>
        <?php if (!$isSelf && !$deleted): ?>
        <div class="member-actions">
        <form method="post" action="/adminmembers/<?= (int) $m['is_locked'] === 1 ? 'unlock' : 'lock' ?>/<?= (int) $m['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t((int) $m['is_locked'] === 1 ? 'adminmembers.unlock' : 'adminmembers.lock') ?></button>
        </form>
        <a href="/adminmembers/remove/<?= (int) $m['id'] ?>" class="btn"><?= \App\Lang::t('adminmembers.delete') ?></a>
        </div>
        <?php endif; ?>
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
