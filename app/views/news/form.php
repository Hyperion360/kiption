<?php // app/views/news/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? 'New post' : 'Edit post' ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/news/create' : '/news/update/' . (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Title <input name="title" required maxlength="255" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label>Body <textarea name="body" rows="12" required placeholder="Markdown body"><?= $this->e($row['body'] ?? '') ?></textarea></label>
    <button type="submit"><?= $row === null ? 'Create' : 'Save' ?></button>
  </form>
</section>
