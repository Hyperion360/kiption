<?php // app/views/page/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? 'New page' : 'Edit page' ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/page/create' : '/page/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($row === null): ?>
      <label>Slug <input name="slug" required placeholder="about" pattern="[a-z0-9-]+"></label>
    <?php else: ?>
      <label>Slug <input value="<?= $this->e($row['slug']) ?>" readonly aria-readonly="true"></label>
    <?php endif; ?>
    <label>Title <input name="title" required maxlength="255" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label>Body <textarea name="body" rows="12" placeholder="Markdown welcome"><?= $this->e($row['body'] ?? '') ?></textarea></label>
    <button type="submit"><?= $row === null ? 'Create' : 'Save' ?></button>
  </form>
</section>
