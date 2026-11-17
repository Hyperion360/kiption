<?php // app/views/adminmembers/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('adminmembers.heading') ?></h1>
<form method="get" action="/adminmembers" class="inline member-search" data-js-module="listfilter" data-filter-rows=".member-table tbody tr" data-filter-text=".member-name, .member-email">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('adminmembers.search_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('adminmembers.search_aria')) ?>">
  <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= $this->e($filter) ?>"><?php endif; ?>
  <button type="submit"><?= \App\Lang::t('adminmembers.search') ?></button>
  <span class="chapter-meta live-filter-note" data-filter-note hidden><?= \App\Lang::t('adminmembers.filter_note') ?></span>
</form>
<nav class="member-filters" aria-label="<?= $this->e(\App\Lang::t('adminmembers.filters_aria')) ?>">
  <?php foreach ($filters as $f => $n): ?>
  <a href="/adminmembers?<?= $this->e(http_build_query(array_filter(['q' => $q, 'filter' => $f === 'all' ? '' : $f], static fn ($v) => $v !== ''))) ?>"<?= $f === $filter ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('adminmembers.filter.' . $f) ?> <span class="queue-tab-n"><?= (int) $n ?></span></a>
  <?php endforeach; ?>
</nav>
<p class="chapter-meta member-total"><?= \App\Lang::t($total === 1 ? 'adminmembers.total_one' : 'adminmembers.total', ['n' => number_format($total)]) ?></p>
<table class="member-table">
  <thead><tr><th scope="col"><?= \App\Lang::t('adminmembers.column_member') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_email') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_role') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_status') ?></th></tr></thead>
  <tbody>
<?php foreach ($rows as $m): ?>
<?php
    $isSelf = (int) $m['id'] === $me;
    $penname = $m['penname'] === null ? \App\Lang::t('adminmembers.no_penname') : (string) $m['penname'];
    $deleted = \App\AccountSecurity::isDeletedEmail((string) $m['email']);
    // One plain word for where the account stands.
    $status = $deleted ? 'deleted' : ((int) $m['is_locked'] === 1 ? 'locked' : ($m['email_verified_at'] === null ? 'unverified' : ($m['approved_at'] === null ? 'unapproved' : 'active')));
?>
    <tr>
      <th scope="row">
        <span class="member-name"><?= $this->e($penname) ?></span><?= $isSelf ? ' ' . \App\Lang::t('adminmembers.you') : '' ?>
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
        <?php if ($isSelf || $deleted): ?>
        <span class="badge"><?= \App\Lang::t('adminmembers.role.' . $m['role']) ?></span>
        <?php else: ?>
        <form method="post" action="/adminmembers/setrole/<?= (int) $m['id'] ?>" class="inline member-role">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <select name="role" aria-label="<?= $this->e(\App\Lang::t('adminmembers.role_for', ['name' => $penname])) ?>">
            <?php foreach ($roles as $r): ?><option value="<?= $this->e($r) ?>"<?= $r === $m['role'] ? ' selected' : '' ?>><?= \App\Lang::t('adminmembers.role.' . $r) ?></option><?php endforeach; ?>
          </select>
          <button type="submit"><?= \App\Lang::t('adminmembers.set_role') ?></button>
        </form>
        <?php endif; ?>
      </td>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_status')) ?>">
        <span class="member-status is-<?= $status ?>"><?= \App\Lang::t('adminmembers.status.' . $status) ?></span>
        <?php if (!$isSelf && !$deleted): ?>
        <details class="member-menu">
          <summary aria-label="<?= $this->e(\App\Lang::t('adminmembers.more_for', ['name' => $penname])) ?>"><?= \App\Lang::t('adminmembers.more') ?></summary>
          <div class="member-menu-list">
            <form method="post" action="/adminmembers/<?= (int) $m['is_locked'] === 1 ? 'unlock' : 'lock' ?>/<?= (int) $m['id'] ?>" class="inline">
              <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
              <button type="submit"><?= \App\Lang::t((int) $m['is_locked'] === 1 ? 'adminmembers.unlock' : 'adminmembers.lock') ?></button>
            </form>
            <a href="/adminmembers/remove/<?= (int) $m['id'] ?>" class="member-delete"><?= \App\Lang::t('adminmembers.delete') ?></a>
          </div>
        </details>
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
$qs = http_build_query(array_filter(['q' => $q, 'filter' => $filter === 'all' ? '' : $filter], static fn ($v) => $v !== ''));
$qs = $qs === '' ? '' : $qs . '&';
if ($page > 1 || !empty($hasMore)): ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
<?php if (!empty($hasMore)): ?><a class="pager-next" href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
