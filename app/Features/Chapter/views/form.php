<?php // app/views/chapter/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit', ['n' => (int) $position]) ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('chapter.for') ?> <a href="/story/edit/<?= $this->e($story['slug']) ?>"><?= $this->e($story['story_title']) ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if (($notice ?? '') !== ''): ?><p class="notice"><?= $this->e($notice) ?></p><?php endif; ?>
  <form id="chapter-editor-form" data-js-module="writer-status draft-form" data-writer-labels="<?= $this->e(json_encode($writerLabels, JSON_THROW_ON_ERROR)) ?>"
    data-writer-owner="<?= $this->e((string) $writerOwner) ?>"
    data-writer-chapter="<?= $this->e($writerChapter) ?>"
    data-writer-base="<?= $this->e($currentRevision) ?>"
    data-writer-protected="<?= $writerProtected ? '1' : '0' ?>"
    data-writer-server="<?= $this->e(json_encode($writerServer, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"
    data-writer-check-url="<?= $this->e($writerCheckUrl) ?>"
    data-writer-saved="<?= ($error ?? null) === null && ($notice ?? '') !== '' ? '1' : '0' ?>"
    data-writer-rejected="<?= ($error ?? null) !== null ? '1' : '0' ?>" method="post" action="<?= $position === null
      ? '/chapter/create/' . $this->e($story['slug'])
      : '/chapter/update/' . $this->e($story['slug']) . '/' . (int) $position ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php if ($position !== null): ?>
      <input type="hidden" name="base_revision" value="<?= $this->e($baseRevision) ?>">
    <?php endif; ?>
    <div class="writer-actions">
      <button type="submit" class="btn-primary"><?= \App\Lang::t('chapter.save') ?></button>
      <span data-writer-state role="status" aria-live="polite" aria-atomic="true"><?= $this->e(($error ?? null) !== null ? $writerLabels['unsaved'] : (($notice ?? '') !== '' ? $writerLabels['saved'] : '')) ?></span>
    </div>
    <?php if ($conflict ?? false): ?>
      <section aria-labelledby="chapter-conflict-heading">
        <h2 id="chapter-conflict-heading"><?= $this->e(\App\Lang::t('chapter.compare_versions')) ?></h2>
        <?php foreach (['chapter.your_text' => $story, 'chapter.archive_version' => $baseRow] as $label => $version): ?>
          <fieldset>
            <legend><?= $this->e(\App\Lang::t($label)) ?></legend>
            <?php foreach (['title' => 'chapter.title', 'content' => 'chapter.text', 'notes_before' => 'chapter.note_before', 'notes_after' => 'chapter.note_after', 'publish_at' => 'chapter.publish_at'] as $field => $labelKey): ?>
              <label><?= $this->e(\App\Lang::t($labelKey)) ?>
                <textarea readonly rows="<?= $field === 'content' ? '6' : '2' ?>"><?= $this->e($version[$field] ?? '') ?></textarea>
              </label>
            <?php endforeach; ?>
          </fieldset>
        <?php endforeach; ?>
        <p><?= $this->e(\App\Lang::t('chapter.replace_explained')) ?></p>
        <button class="btn" type="submit" name="accept_revision" value="<?= $this->e($currentRevision) ?>"><?= $this->e(\App\Lang::t('chapter.replace_shown')) ?></button>
      </section>
    <?php endif; ?>
    <label><?= \App\Lang::t('chapter.title') ?> <input name="title" maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('chapter.note_before') ?> <textarea name="notes_before" rows="2"><?= $this->e($story['notes_before'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('chapter.text') ?> <textarea name="content" rows="18" required aria-describedby="writer-count" data-js-module="paste editor" data-editor-labels="<?= $this->e(json_encode([
        'bold' => \App\Lang::t('chapter.editor_bold'),
        'italic' => \App\Lang::t('chapter.editor_italic'),
        'link' => \App\Lang::t('chapter.editor_link'),
        'quote' => \App\Lang::t('chapter.editor_quote'),
        'break' => \App\Lang::t('chapter.editor_break'),
    ], JSON_UNESCAPED_UNICODE)) ?>"><?= $this->e($story['content'] ?? '') ?></textarea></label>
    <div class="chapter-meta">
      <output id="writer-count" data-writer-count aria-live="off"><?= $this->e(\App\Lang::t('writer.words', ['n' => \App\Markdown::wordCount((string) ($story['content'] ?? ''))])) ?></output>
      <button type="button" data-writer-count-selection hidden><?= $this->e(\App\Lang::t('writer.count_selection')) ?></button>
      <span data-writer-selection role="status" aria-live="polite" aria-atomic="true"></span>
    </div>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.markdown_help') ?></p>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.paste_hint') ?></p>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.editor_hint') ?></p>
    <label><?= \App\Lang::t('chapter.note_after') ?> <textarea name="notes_after" rows="2"><?= $this->e($story['notes_after'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('chapter.publish_at') ?> <input type="datetime-local" name="publish_at" value="<?= $this->e(($error ?? null) !== null ? (string) ($story['publish_at'] ?? '') : substr((string) ($story['publish_at'] ?? ''), 0, 16)) ?>"></label>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.publish_hint') ?></p>
    <label><?= \App\Lang::t('chapter.access_password_label') ?> <input type="password" name="access_password" autocomplete="new-password"></label>
    <label class="inline"><input type="checkbox" name="access_clear" value="1"<?= ($story['access_clear'] ?? false) ? ' checked' : '' ?>> <?= \App\Lang::t('chapter.access_clear_label') ?></label>
  </form>
  <?php if ($position !== null): ?>
    <form method="post" action="/chapter/delete/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit"><?= \App\Lang::t('chapter.delete') ?></button>
    </form>
  <?php endif; ?>
</section>
