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
    <?php if ($story === null): ?><p class="hint story-intro"><?= \App\Lang::t('story.new_intro') ?></p><?php endif; ?>
    <?php if ($story !== null): ?>
    <div class="writer-actions">
      <button type="submit" class="btn-primary"><?= $story === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
      <span data-writer-state role="status" aria-live="polite" aria-atomic="true"><?= $this->e(($error ?? null) !== null ? $writerLabels['unsaved'] : (($notice ?? '') !== '' ? $writerLabels['saved'] : '')) ?></span>
    </div>
    <?php endif; ?>
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="200" value="<?= $this->e($values['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="1000" rows="3"><?= "\n" . $this->e($values['summary'] ?? '') ?></textarea></label>
    <?php $chosenLanguage = (string) ($values['language'] ?? ($story === null ? \App\Languages::DEFAULT : '')); ?>
    <label><?= \App\Lang::t('common.language') ?>
      <select name="language">
        <option value=""<?= $chosenLanguage === '' ? ' selected' : '' ?>><?= \App\Lang::t('story.language_unset') ?></option>
        <?php foreach (\App\Languages::options($chosenLanguage) as $code => $name): ?>
          <option value="<?= $this->e($code) ?>"<?= $chosenLanguage === $code ? ' selected' : '' ?>><?= $this->e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('story.rating') ?>
      <select name="rating_id" required>
        <?php if ($ratingInvalid): ?><option value="" selected disabled><?= \App\Lang::t('story.choose_rating') ?></option><?php endif; ?>
        <?php foreach ($ratings as $r): ?>
          <option value="<?= (int) $r['a'] ?>" <?= isset($values['rating_id']) && (int) $values['rating_id'] === (int) $r['a'] ? 'selected' : '' ?>><?= $this->e($r['b']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (\App\Settings::on('categories_on')): ?>
    <fieldset>
      <legend><?= \App\Lang::t('story.categories') ?></legend>
      <?php foreach ($categories as $c): ?>
        <label class="inline">
          <input type="checkbox" name="categories[]" value="<?= (int) $c['a'] ?>"
            <?= in_array((string) $c['a'], array_map('strval', $selectedCategories), true) ? 'checked' : '' ?>>
          <?= $this->e($c['b']) ?>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
    <?php if ($story === null): ?>
    <div class="writer-actions story-create">
      <button type="submit" class="btn-primary"><?= $story === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
      <span data-writer-state role="status" aria-live="polite" aria-atomic="true"><?= $this->e(($error ?? null) !== null ? $writerLabels['unsaved'] : (($notice ?? '') !== '' ? $writerLabels['saved'] : '')) ?></span>
    </div>
    <details class="more-options">
      <summary><?= \App\Lang::t('story.more_options') ?></summary>
      <p class="hint"><?= \App\Lang::t('story.more_options_hint') ?></p>
    <?php endif; ?>
    <?= $this->render('story/_more_fields', ['skin' => 'form', 'values' => $values, 'tagGroups' => $tagGroups, 'selectedTags' => $selectedTags,
        'tagPolicy' => $tagPolicy ?? 'off', 'draftSuggest' => $draftSuggest, 'suggestions' => $suggestions, 'draftWithdraw' => $draftWithdraw,
        'suggestRefused' => $suggestRefused ?? [], 'characterGroups' => $characterGroups ?? [], 'selectedCharacters' => $selectedCharacters ?? [],
        'passwordDiscarded' => $passwordDiscarded]) ?>
    <?php if ($story !== null): ?>
    <label><?= \App\Lang::t('story.canonical_label') ?> <input name="canonical_url" maxlength="200" value="<?= $this->e($values['canonical_url'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('story.crosspost_label') ?> <input name="crosspost_url" maxlength="200" value="<?= $this->e($values['crosspost_url'] ?? '') ?>"></label>
    <p class="chapter-meta"><?= \App\Lang::t('story.syndication_help') ?></p>
    <?php endif; ?>
    <?php if ($story === null): ?></details><?php endif; ?>
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
            <span class="chapter-meta">(<?= (int) ($ch['is_draft'] ?? 0) === 1 ? \App\Lang::t('chapter.status_draft') : ((int) $ch['validated'] === 1 ? \App\Lang::t('story.live') : \App\Lang::t('story.awaiting_validation')) ?>)</span>
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
