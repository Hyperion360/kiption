<?php // app/views/browse/authors.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<?php if ($beta): ?><p class="meta">Showing beta readers only.</p><?php endif; ?>
<nav class="chapter-meta" aria-label="Filter by first letter">
  <a href="/browse/authors<?= $beta ? '?beta=1' : '' ?>"<?= $letter === '' ? ' aria-current="page"' : '' ?>>All</a> |
  <?php foreach (range('a', 'z') as $l): ?>
    <a href="/browse/authors/<?= $l ?><?= $beta ? '?beta=1' : '' ?>"<?= $letter === $l ? ' aria-current="page"' : '' ?>><?= strtoupper($l) ?></a>
  <?php endforeach; ?> |
  <a href="/browse/authors/0<?= $beta ? '?beta=1' : '' ?>"<?= $letter === '0' ? ' aria-current="page"' : '' ?>>#</a>
</nav>
<table>
  <thead><tr><th scope="col">Member</th><th scope="col">Works</th><th scope="col">Joined</th></tr></thead>
  <tbody>
<?php foreach ($members as $m): ?>
    <tr>
      <td>
        <a href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= $this->e($m['penname']) ?></a>
        <?php if ((int) $m['is_beta'] === 1): ?><span class="badge">Beta reader</span><?php endif; ?>
      </td>
      <td><?= number_format((int) $m['story_count']) ?></td>
      <td><?= $this->e(substr((string) $m['created_at'], 0, 10)) ?></td>
    </tr>
<?php endforeach; ?>
<?php if ($members === []): ?>
    <tr><td colspan="3" class="meta">No members here yet.</td></tr>
<?php endif; ?>
  </tbody>
</table>
<?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $beta ? '?beta=1&amp;page=' : '?page=' ?><?= $page - 1 ?>">Previous</a><?php endif; ?>
<a href="<?= $this->e($baseUrl) ?><?= $beta ? '?beta=1&amp;page=' : '?page=' ?><?= $page + 1 ?>">Next</a>
