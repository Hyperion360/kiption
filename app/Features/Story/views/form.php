<?php // app/views/story/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $story === null ? \App\Lang::t('story.new_heading') : \App\Lang::t('story.edit_heading') ?></h1>
  <?php if (($notice ?? '') !== ''): ?><div class="prose notice"><?= $notice /* Markdown, every character escaped */ ?></div><?php endif; ?>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form id="story-editor-form" data-js-module="writer-status" data-writer-labels="<?= $this->e(json_encode($writerLabels, JSON_THROW_ON_ERROR)) ?>"
    data-writer-saved="<?= ($error ?? null) === null && ($notice ?? '') !== '' ? '1' : '0' ?>"
    data-writer-rejected="<?= ($error ?? null) !== null ? '1' : '0' ?>" method="post" action="<?= $story === null ? '/story/create' : '/story/update/' . $this->e($story['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <div class="writer-actions">
      <button type="submit" class="btn-primary"><?= $story === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
      <span data-writer-state role="status" aria-live="polite" aria-atomic="true"><?= $this->e(($error ?? null) !== null ? $writerLabels['unsaved'] : (($notice ?? '') !== '' ? $writerLabels['saved'] : '')) ?></span>
    </div>
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
      <?php foreach ($tagGroups as $typeId => $group): ?>
        <p class="chapter-meta"><?= $this->e($group['name']) ?></p>
        <?php foreach ($group['tags'] as $tagId => $tagName): ?>
        <label class="inline">
          <input type="checkbox" name="tags[]" value="<?= (int) $tagId ?>"<?= in_array((string) $tagId, array_map('strval', $selectedTags), true) ? ' checked' : '' ?>>
          <?= $this->e($tagName) ?>
        </label>
        <?php endforeach; ?>
        <?php if (($tagPolicy ?? 'off') !== 'off'): ?>
        <label><?= \App\Lang::t('story.suggest_label') ?> <input name="suggest[<?= (int) $typeId ?>]" maxlength="300"></label>
        <?php endif; ?>
        <?php $pending = $suggestions[$typeId]['pending'] ?? []; $rejected = $suggestions[$typeId]['rejected'] ?? []; ?>
        <?php if ($pending !== [] || $rejected !== []): ?>
        <p class="chapter-meta"><?= \App\Lang::t('story.suggest_pending') ?></p>
        <?php foreach ($pending as $s): ?>
        <label class="inline">
          <input type="checkbox" name="withdraw[]" value="<?= (int) $s['id'] ?>"> <?= $this->e($s['name']) ?> (<?= \App\Lang::t('story.suggest_withdraw') ?>)
        </label>
        <?php endforeach; ?>
        <?php foreach ($rejected as $s): ?>
        <p class="hint"><?= $this->e($s['name']) ?>: <?= \App\Lang::t('story.suggest_rejected') ?></p>
        <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php $hint = ($tagPolicy ?? 'off') === 'free' ? 'story.suggest_hint_free' : 'story.suggest_hint'; ?>
      <p class="hint"><?= \App\Lang::t($hint) ?></p>
    </fieldset>
    <?php endif; ?>
    <?php if (($suggestRefused ?? []) !== []): ?>
    <p class="error"><?= \App\Lang::t('story.suggest_refused', ['names' => $this->e(implode(', ', $suggestRefused))]) ?></p>
    <?php endif; ?>
    <?php if (($characterGroups ?? []) !== []): ?>
    <fieldset>
      <legend><?= \App\Lang::t('story.characters') ?></legend>
      <input type="hidden" name="characters_shown" value="1">
      <p class="hint"><?= \App\Lang::t('story.characters_help') ?></p>
      <?php foreach ($characterGroups as $group): ?>
        <p class="chapter-meta"><?= $group['name'] === '' ? \App\Lang::t('characters.any_category') : $this->e($group['name']) ?></p>
        <?php foreach ($group['characters'] as $characterId => $characterName): ?>
        <label class="inline">
          <input type="checkbox" name="characters[]" value="<?= (int) $characterId ?>"<?= in_array((string) $characterId, array_map('strval', $selectedCharacters ?? []), true) ? ' checked' : '' ?>>
          <?= $this->e($characterName) ?>
        </label>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <label class="inline"><input type="checkbox" name="completed" value="1" <?= $story !== null && (int) $story['completed'] === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.completed') ?></label>
    <label class="inline"><input type="checkbox" name="restricted" value="1" <?= $story !== null && (int) $story['restricted'] === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.registered_only') ?></label>
    <label><?= \App\Lang::t('story.access_password_label') ?> <input type="password" name="access_password" autocomplete="new-password"></label>
    <label class="inline"><input type="checkbox" name="access_clear" value="1"> <?= \App\Lang::t('story.access_clear_label') ?></label>
    <label class="inline"><input type="checkbox" name="round_robin" value="1" <?= $story !== null && (int) ($story['round_robin'] ?? 0) === 1 ? 'checked' : '' ?>> <?= \App\Lang::t('story.round_robin') ?></label>
    <label class="inline"><input type="checkbox" name="is_original" value="1"<?= $story !== null && (int) ($story['is_original'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('story.is_original_label') ?></label>
    <p class="hint"><?= \App\Lang::t('story.is_original_help') ?></p>
    <label><?= \App\Lang::t('story.gift_to_label') ?> <input name="gift_to" maxlength="120" value="<?= $this->e($story['gift_to'] ?? '') ?>"></label>
    <?php if ($story !== null): ?>
    <label><?= \App\Lang::t('story.canonical_label') ?> <input name="canonical_url" maxlength="200" value="<?= $this->e($story['canonical_url'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('story.crosspost_label') ?> <input name="crosspost_url" maxlength="200" value="<?= $this->e($story['crosspost_url'] ?? '') ?>"></label>
    <p class="chapter-meta"><?= \App\Lang::t('story.syndication_help') ?></p>
    <?php endif; ?>
  </form>
  <?php if ($story !== null): ?>
    <form class="form-aside" method="post" action="/story/cover/<?= $this->e($story['slug']) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label><?= \App\Lang::t('story.cover_label') ?> <input type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/gif"></label>
      <button type="submit"><?= \App\Lang::t('story.upload_cover') ?></button>
    </form>
    <?php if (!empty($canManageCoauthors) && \App\Settings::on('coauthors_on')): ?>
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
