<?php // app/views/story/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $story === null ? \App\Lang::t('story.new_heading') : \App\Lang::t('story.edit_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $story === null ? '/story/create' : '/story/update/' . $this->e($story['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="1000" rows="3"><?= $this->e($story['summary'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('story.notes') ?> <textarea name="notes" rows="2"><?= $this->e($story['notes'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('common.language_hint') ?> <input name="language" maxlength="10" value="<?= $this->e($story['language'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('story.rating') ?>
      <select name="rating_id">
        <?php foreach ($ratings as $r): ?>
          <option value="<?= (int) $r['a'] ?>" <?= $story !== null && (int) $story['rating_id'] === (int) $r['a'] ? 'selected' : '' ?>><?= $this->e($r['b']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <fieldset>
      <legend><?= \App\Lang::t('story.categories') ?></legend>
      <?php foreach ($categories as $c): ?>
        <label class="inline">
          <input type="checkbox" name="categories[]" value="<?= (int) $c['a'] ?>"
            <?= $story !== null && in_array((string) $c['a'], array_map('strval', $selectedCategories), true) ? 'checked' : '' ?>>
          <?= $this->e($c['b']) ?>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <?php if ($tagGroups !== []): ?>
    <fieldset>
      <legend><?= \App\Lang::t('story.tags') ?></legend>
      <?php foreach ($tagGroups as $group): ?>
        <p class="chapter-meta"><?= $this->e($group['name']) ?></p>
        <?php foreach ($group['tags'] as $tagId => $tagName): ?>
        <label class="inline">
          <input type="checkbox" name="tags[]" value="<?= (int) $tagId ?>"<?= in_array((string) $tagId, array_map('strval', $selectedTags), true) ? ' checked' : '' ?>>
          <?= $this->e($tagName) ?>
        </label>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <label class="inline"><input type="checkbox" name="completed" value="1" <?= $story !== null && (int) $story['completed'] === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.completed') ?></label>
    <label class="inline"><input type="checkbox" name="restricted" value="1" <?= $story !== null && (int) $story['restricted'] === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.registered_only') ?></label>
    <label class="inline"><input type="checkbox" name="round_robin" value="1" <?= $story !== null && (int) ($story['round_robin'] ?? 0) === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.round_robin') ?></label>
    <label><?= \App\Lang::t('story.gift_to_label') ?> <input name="gift_to" maxlength="120" value="<?= $this->e($story['gift_to'] ?? '') ?>"></label>
    <?php if ($story !== null): ?>
    <label><?= \App\Lang::t('story.canonical_label') ?> <input name="canonical_url" maxlength="200" value="<?= $this->e($story['canonical_url'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('story.crosspost_label') ?> <input name="crosspost_url" maxlength="200" value="<?= $this->e($story['crosspost_url'] ?? '') ?>"></label>
    <p class="chapter-meta"><?= \App\Lang::t('story.syndication_help') ?></p>
    <?php endif; ?>
    <button type="submit"><?= $story === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
  <?php if ($story !== null): ?>
    <form method="post" action="/story/cover/<?= $this->e($story['slug']) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('story.cover_label') ?> <input type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/gif"></label>
      <button type="submit"><?= \App\Lang::t('story.upload_cover') ?></button>
    </form>
    <?php if (!empty($canManageCoauthors)): ?>
    <h2><?= \App\Lang::t('story.coauthors') ?></h2>
    <?php if ($coauthors === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?></p>
    <?php else: ?>
    <ul>
      <?php foreach ($coauthors as $co): ?>
      <li>
        <?= $this->e($co['penname']) ?>
        <form method="post" action="/coauthor/remove/<?= $this->e($story['slug']) ?>/<?= (int) $co['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="/coauthor/add/<?= $this->e($story['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('story.add_coauthor_label') ?> <input name="penname" maxlength="30" required></label>
      <button type="submit"><?= \App\Lang::t('story.add_coauthor') ?></button>
    </form>
    <?php endif; ?>
    <?php if ($chapters !== []): ?>
      <h2><?= \App\Lang::t('story.chapters') ?></h2>
      <ul>
        <?php foreach ($chapters as $ch): ?>
          <li>
            <a href="/chapter/edit/<?= $this->e($story['slug']) ?>/<?= (int) $ch['position'] ?>"><?= \App\Lang::t('story.chapter_n', ['n' => (int) $ch['position']]) ?>: <?= $this->e($ch['title'] !== '' ? $ch['title'] : \App\Lang::t('story.untitled')) ?></a>
            <span class="chapter-meta">(<?= (int) $ch['validated'] === 1 ? \App\Lang::t('story.live') : \App\Lang::t('story.awaiting_validation') ?>)</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a href="/chapter/new/<?= $this->e($story['slug']) ?>"><?= \App\Lang::t('story.add_chapter') ?></a></p>
    <form method="post" action="/story/delete/<?= $this->e($story['slug']) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit"><?= \App\Lang::t('story.delete_story') ?></button>
    </form>
  <?php endif; ?>
</section>
