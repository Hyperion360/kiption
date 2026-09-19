<?php // app/views/chapter/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $position === null ? 'New chapter' : 'Edit chapter ' . (int) $position ?></h1>
  <p class="chapter-meta">for <a href="/story/edit/<?= $this->e($story['slug']) ?>"><?= $this->e($story['story_title']) ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $position === null
      ? '/chapter/create/' . $this->e($story['slug'])
      : '/chapter/update/' . $this->e($story['slug']) . '/' . (int) $position ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Chapter title <input name="title" maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label>Author note (before) <textarea name="notes_before" rows="2"><?= $this->e($story['notes_before'] ?? '') ?></textarea></label>
    <label>Text <textarea name="content" rows="18" required><?= $this->e($story['content'] ?? '') ?></textarea></label>
    <p class="chapter-meta">Markdown: *italic*, **bold**, a line of --- for a scene break, > for quotes, [text](https://...). HTML is not available.</p>
    <label>Author note (after) <textarea name="notes_after" rows="2"><?= $this->e($story['notes_after'] ?? '') ?></textarea></label>
    <button type="submit">Save chapter</button>
  </form>
  <?php if ($position !== null): ?>
    <form method="post" action="/chapter/delete/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit">Delete chapter</button>
    </form>
  <?php endif; ?>
</section>
