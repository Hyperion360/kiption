<?php // app/views/images/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Image library</h1>
<p class="meta"><?= $count === 1 ? '1 file' : $count . ' files' ?>, <?= $this->e($totalHuman) ?> total.</p>
<form method="post" action="/images/upload" enctype="multipart/form-data" class="inline">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="file" name="file" id="file" accept="image/png,image/jpeg,image/webp,image/gif" aria-label="Upload an image file">
  <button type="submit">Upload</button>
</form>
<p class="meta">PNG, JPG, WEBP, or GIF under 2 MiB. Files an avatar or story cover still uses cannot be deleted.</p>
<table>
  <thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">Actions</th></tr></thead>
  <tbody>
<?php foreach ($files as $f): ?>
    <tr>
      <td><code><?= $this->e($f['name']) ?></code></td>
      <td><?= $this->e($f['human']) ?></td>
      <td>
        <?php if ($f['inUse']): ?>
          <span class="badge">In use</span>
        <?php else: ?>
        <form method="post" action="/images/delete" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <input type="hidden" name="name" value="<?= $this->e($f['name']) ?>">
          <button type="submit">Delete</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
<?php if ($files === []): ?>
    <tr><td colspan="3" class="meta">No files uploaded.</td></tr>
<?php endif; ?>
  </tbody>
</table>
