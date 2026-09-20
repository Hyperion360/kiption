<?php // app/views/series/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? 'New series' : 'Edit series' ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/series/create' : '/series/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Title <input name="title" required maxlength="120" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label>Summary <textarea name="summary" maxlength="2000" rows="3"><?= $this->e($row['summary'] ?? '') ?></textarea></label>
    <label>Membership
      <select name="membership">
        <?php foreach (['open', 'moderated', 'closed'] as $m): ?>
          <option value="<?= $m ?>"<?= ($row['membership'] ?? 'open') === $m ? ' selected' : '' ?>><?= $this->e($m) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= $row === null ? 'Create' : 'Save' ?></button>
  </form>
</section>
