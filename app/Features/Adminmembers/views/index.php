<?php // app/views/adminmembers/index.php ?>
<?php $this->layout('layout'); ?>
<div class="member-head">
  <h1><?= \App\Lang::t('adminmembers.heading') ?></h1> <span class="member-count"><?= number_format((int) $filters['all']) ?></span>
  <p class="member-shortcuts"><a class="btn" href="/invites"><?= \App\Lang::t('manage.invites') ?></a> <a class="btn" href="/mailusers"><?= \App\Lang::t('manage.mailusers') ?></a></p>
</div>
<form method="get" action="/adminmembers" class="inline member-search" data-js-module="listfilter" data-filter-rows=".member-table tbody tr" data-filter-text=".member-name, .member-email">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('adminmembers.search_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('adminmembers.search_aria')) ?>">
  <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= $this->e($filter) ?>"><?php endif; ?>
  <span class="chapter-meta live-filter-note" data-filter-note hidden><?= \App\Lang::t('adminmembers.filter_note') ?></span>
</form>
<nav class="member-filters" aria-label="<?= $this->e(\App\Lang::t('adminmembers.filters_aria')) ?>">
  <?php foreach ($filters as $f => $n): ?>
  <?php $chip = http_build_query(array_filter(['q' => $q, 'filter' => $f === 'all' ? '' : $f], static fn ($v) => $v !== '')); ?>
  <a href="/adminmembers<?= $chip === '' ? '' : '?' . $this->e($chip) ?>"<?= $f === $filter ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('adminmembers.filter.' . $f) ?> <span class="queue-tab-n"><?= (int) $n ?></span></a>
  <?php endforeach; ?>
</nav>
<table class="member-table">
  <thead><tr><th scope="col"><?= \App\Lang::t('adminmembers.column_member') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_role') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_status') ?></th><th scope="col" class="member-num"><?= \App\Lang::t('adminmembers.column_stories') ?></th><th scope="col"><?= \App\Lang::t('adminmembers.column_signed_in') ?></th><th scope="col"><span class="visually-hidden"><?= \App\Lang::t('adminmembers.column_actions') ?></span></th></tr></thead>
  <tbody>
<?php foreach ($rows as $m): ?>
<?php
    $isSelf = (int) $m['id'] === $me;
    $penname = $m['penname'] === null ? \App\Lang::t('adminmembers.no_penname') : (string) $m['penname'];
    $deleted = \App\AccountSecurity::isDeletedEmail((string) $m['email']);
    // One plain word for where the account stands.
    $status = $deleted ? 'deleted' : ((int) $m['is_locked'] === 1 ? 'locked' : ($m['email_verified_at'] === null ? 'unverified' : ($m['approved_at'] === null ? 'unapproved' : 'active')));
    $open = (int) $m['open_reports'];
    $signed = '';
    if ($m['last_signin'] !== null) {
        $day = date('Y-m-d', (int) strtotime((string) $m['last_signin']));
        $signed = $day === date('Y-m-d') ? \App\Lang::t('adminmembers.today') : ($day === date('Y-m-d', strtotime('-1 day')) ? \App\Lang::t('adminmembers.yesterday') : $this->e(\App\Dates::format((string) $m['last_signin'], false)));
    }
?>
    <tr>
      <th scope="row">
        <span class="member-name"><?= $this->e($penname) ?></span><?= $isSelf ? ' <span class="member-you">' . \App\Lang::t('adminmembers.you') . '</span>' : '' ?>
        <?php if ($open > 0): ?><span class="member-reports"><?= \App\Lang::t($open === 1 ? 'adminmembers.open_reports_one' : 'adminmembers.open_reports', ['n' => $open]) ?></span><?php endif; ?>
        <span class="member-email"><?= $this->e($m['email']) ?></span>
      </th>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_role')) ?>">
        <?php if ($isSelf || $deleted): ?>
        <span class="member-role-fixed"><?= \App\Lang::t('adminmembers.role.' . $m['role']) ?></span>
        <?php else: ?>
        <form method="post" action="/adminmembers/setrole/<?= (int) $m['id'] ?>" class="inline member-role" data-autosubmit>
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <select name="role" aria-label="<?= $this->e(\App\Lang::t('adminmembers.role_for', ['name' => $penname])) ?>">
            <?php foreach ($roles as $r): ?><option value="<?= $this->e($r) ?>"<?= $r === $m['role'] ? ' selected' : '' ?>><?= \App\Lang::t('adminmembers.role.' . $r) ?></option><?php endforeach; ?>
          </select>
          <noscript><button type="submit"><?= \App\Lang::t('adminmembers.set_role') ?></button></noscript>
        </form>
        <?php endif; ?>
      </td>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_status')) ?>">
        <span class="member-status is-<?= $status ?>"><?= \App\Lang::t('adminmembers.status.' . $status) ?></span><?php if ($status === 'unapproved'): ?> <form method="post" action="/queue/member/<?= (int) $m['id'] ?>/approve" class="inline member-approve-form"><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><input type="hidden" name="back" value="<?= $this->e('/adminmembers' . ($request->get === [] ? '' : '?' . http_build_query($request->get))) ?>"><button type="submit" class="member-approve"><?= \App\Lang::t('adminmembers.approve') ?></button></form><?php endif; ?>
      </td>
      <td class="member-num" data-label="<?= $this->e(\App\Lang::t('adminmembers.column_stories')) ?>"><?= (int) $m['stories'] ?></td>
      <td data-label="<?= $this->e(\App\Lang::t('adminmembers.column_signed_in')) ?>"><?= $m['last_signin'] !== null ? $signed : \App\Lang::t('adminmembers.never_signed_in') ?></td>
      <td class="member-act">
        <?php if (!$isSelf && !$deleted): ?>
        <details class="member-menu">
          <summary aria-label="<?= $this->e(\App\Lang::t('adminmembers.more_for', ['name' => $penname])) ?>"><span aria-hidden="true"><?= \App\Lang::t('adminmembers.more') ?></span></summary>
          <div class="member-menu-list">
            <?php if ($m['profile_slug'] !== null): ?><a href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= \App\Lang::t('adminmembers.view_profile') ?></a><?php endif; ?>
            <?php if ($open > 0): ?><a href="/queue?tab=reports"><?= \App\Lang::t($open === 1 ? 'adminmembers.see_reports_one' : 'adminmembers.see_reports', ['n' => $open]) ?></a><?php endif; ?>
            <form method="post" action="/adminmembers/<?= (int) $m['is_locked'] === 1 ? 'unlock' : 'lock' ?>/<?= (int) $m['id'] ?>" class="inline">
              <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
              <button type="submit"><?= \App\Lang::t((int) $m['is_locked'] === 1 ? 'adminmembers.unlock_account' : 'adminmembers.lock_account') ?></button>
            </form>
            <a href="/adminmembers/remove/<?= (int) $m['id'] ?>" class="member-delete"><?= \App\Lang::t('adminmembers.delete_member') ?></a>
          </div>
        </details>
        <?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
    <tr><td colspan="6" class="meta"><?= \App\Lang::t('adminmembers.no_match') ?></td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php
$qs = http_build_query(array_filter(['q' => $q, 'filter' => $filter === 'all' ? '' : $filter], static fn ($v) => $v !== ''));
$qs = $qs === '' ? '' : $qs . '&';
?>
<div class="member-foot">
  <span class="member-range"><?= \App\Lang::t('adminmembers.range', ['from' => number_format($from), 'to' => number_format($to), 'total' => number_format($total)]) ?></span>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
<?php if ($page > 1): ?><a href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php else: ?><span class="pager-off" aria-disabled="true"><?= \App\Lang::t('common.previous') ?></span><?php endif; ?>
<?php if (!empty($hasMore)): ?><a class="pager-next" href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php else: ?><span class="pager-off" aria-disabled="true"><?= \App\Lang::t('common.next') ?></span><?php endif; ?>
  </nav>
</div>
