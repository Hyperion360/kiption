<?php // app/views/story/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $story === null ? 'New story' : 'Edit story' ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $story === null ? '/story/create' : '/story/update/' . $this->e($story['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Title <input name="title" required maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label>Summary <textarea name="summary" maxlength="1000" rows="3"><?= $this->e($story['summary'] ?? '') ?></textarea></label>
    <label>Notes <textarea name="notes" rows="2"><?= $this->e($story['notes'] ?? '') ?></textarea></label>
    <label>Language (e.g. en, pt-BR) <input name="language" maxlength="10" value="<?= $this->e($story['language'] ?? '') ?>"></label>
    <label>Rating
      <select name="rating_id">
        <?php foreach ($ratings as $r): ?>
          <option value="<?= (int) $r['a'] ?>" <?= $story !== null && (int) $story['rating_id'] === (int) $r['a'] ? 'selected' : '' ?>><?= $this->e($r['b']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <fieldset>
      <legend>Categories</legend>
      <?php foreach ($categories as $c): ?>
        <label class="inline">
          <input type="checkbox" name="categories[]" value="<?= (int) $c['a'] ?>"
            <?= $story !== null && in_array((string) $c['a'], array_map('strval', $selectedCategories), true) ? 'checked' : '' ?>>
          <?= $this->e($c['b']) ?>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <label class="inline"><input type="checkbox" name="completed" value="1" <?= $story !== null && (int) $story['completed'] === 1 ? 'checked' : '' ?>> Completed</label>
    <label class="inline"><input type="checkbox" name="restricted" value="1" <?= $story !== null && (int) $story['restricted'] === 1 ? 'checked' : '' ?>> Registered readers only</label>
    <button type="submit"><?= $story === null ? 'Create' : 'Save' ?></button>
  </form>
  <?php if ($story !== null): ?>
    <form method="post" action="/story/cover/<?= $this->e($story['slug']) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label>Cover (PNG/JPG/WEBP/GIF, max 2 MiB) <input type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/gif"></label>
      <button type="submit">Upload cover</button>
    </form>
    <?php if ($chapters !== []): ?>
      <h2>Chapters</h2>
      <ul>
        <?php foreach ($chapters as $ch): ?>
          <li>
            <a href="/chapter/edit/<?= $this->e($story['slug']) ?>/<?= (int) $ch['position'] ?>">Chapter <?= (int) $ch['position'] ?>: <?= $this->e($ch['title'] !== '' ? $ch['title'] : 'untitled') ?></a>
            <span class="chapter-meta">(<?= (int) $ch['validated'] === 1 ? 'live' : 'awaiting validation' ?>)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a href="/chapter/new/<?= $this->e($story['slug']) ?>">Add a chapter</a></p>
    <form method="post" action="/story/delete/<?= $this->e($story['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit">Delete story</button>
    </form>
  <?php endif; ?>
</section>
