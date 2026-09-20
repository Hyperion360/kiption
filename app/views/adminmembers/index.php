<?php // app/views/adminmembers/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Members</h1>
<form method="get" action="/adminmembers" class="inline">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Penname prefix" aria-label="Search members by penname prefix">
  <button type="submit">Search</button>
</form>
<table>
  <thead><tr><th scope="col">Member</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th></tr></thead>
  <tbody>
<?php foreach ($rows as $m): ?>
<?php
    $isSelf = (int) $m['id'] === $me;
    $penname = $m['penname'] === null ? '(no penname)' : (string) $m['penname'];
?>
    <tr>
      <td>
        <?= $this->e($penname) ?><?= $isSelf ? ' (you)' : '' ?>
        <?php if ($m['profile_slug'] !== null): ?>
          <a href="/user/view/<?= $this->e($m['profile_slug']) ?>">profile</a>
        <?php endif; ?>
      </td>
      <td><?= $this->e($m['email']) ?></td>
      <td>
        <span class="badge"><?= $this->e($m['role']) ?></span>
        <?php foreach ($roles as $r): if ($r !== $m['role']): ?>
        <form method="post" action="/adminmembers/role/<?= (int) $m['id'] ?>/<?= $this->e($r) ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"<?= $isSelf ? ' onclick="return confirm(\'Demote yourself? You will lose admin access.\')"' : '' ?>><?= $this->e($r) ?></button>
        </form>
        <?php endif; endforeach; ?>
      </td>
      <td>
        <?php if ((int) $m['is_locked'] === 1): ?><span class="badge">Locked</span><?php endif; ?>
        <?php if ($m['email_verified_at'] === null): ?><span class="badge">Unverified</span><?php endif; ?>
        <?php if ($m['approved_at'] === null): ?><span class="badge">Unapproved</span><?php endif; ?>
        <?php if ((int) $m['is_locked'] === 0 && $m['email_verified_at'] !== null && $m['approved_at'] !== null): ?><span class="chapter-meta">active</span><?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
    <tr><td colspan="4" class="meta">No members match.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php
$qs = $q === '' ? '' : http_build_query(['q' => $q]) . '&';
if ($page > 1): ?><a href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page - 1 ?>">Previous</a><?php endif; ?>
<a href="/adminmembers?<?= $this->e($qs) ?>page=<?= $page + 1 ?>">Next</a>
