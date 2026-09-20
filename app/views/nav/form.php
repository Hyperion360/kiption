<?php // app/views/nav/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Nav links</h1>
  <?php if ($rows === []): ?><p class="chapter-meta">No links yet.</p><?php else: ?>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <strong><?= $this->e($r['label']) ?></strong><?= (int) $r['is_hidden'] === 1 ? ' (hidden)' : '' ?>
        <span class="chapter-meta"><?= $this->e($r['url']) ?>, position <?= (int) $r['position'] ?></span>
        <a href="/nav/edit/<?= (int) $r['id'] ?>">Edit</a>
        <form method="post" action="/nav/delete/<?= (int) $r['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Delete</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= $row === null ? 'New link' : 'Edit link' ?></h2>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/nav/create' : '/nav/update/' . (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Label <input name="label" required maxlength="40" value="<?= $this->e($row['label'] ?? '') ?>"></label>
    <label>URL <input name="url" required value="<?= $this->e($row['url'] ?? '') ?>" placeholder="/page/about"></label>
    <label>Position <input name="position" value="<?= $this->e((string) ($row['position'] ?? '')) ?>"></label>
    <label><input type="checkbox" name="is_hidden" value="1"<?= (int) ($row['is_hidden'] ?? 0) === 1 ? ' checked' : '' ?>> Hidden</label>
    <button type="submit"><?= $row === null ? 'Create' : 'Save' ?></button>
  </form>
</section>
