<?php // app/views/chapter/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit', ['n' => (int) $position]) ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('chapter.for') ?> <a href="/story/edit/<?= $this->e($story['slug']) ?>"><?= $this->e($story['story_title']) ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if (($notice ?? '') !== ''): ?><p class="notice" role="status"><?= $this->e($notice) ?></p><?php endif; ?>
  <form id="chapter-editor-form" data-js-module="writer-status draft-form" method="post" action="<?= $position === null
      ? '/chapter/create/' . $this->e($story['slug'])
      : '/chapter/update/' . $this->e($story['slug']) . '/' . (int) $position ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <div class="writer-actions">
      <button type="submit" class="btn-primary"><?= \App\Lang::t('chapter.save') ?></button>
      <span data-writer-state role="status" aria-live="polite"></span>
    </div>
    <label><?= \App\Lang::t('chapter.title') ?> <input name="title" maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('chapter.note_before') ?> <textarea name="notes_before" rows="2"><?= $this->e($story['notes_before'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('chapter.text') ?> <textarea name="content" rows="18" required data-js-module="paste editor" data-editor-labels="<?= $this->e(json_encode([
        'bold' => \App\Lang::t('chapter.editor_bold'),
        'italic' => \App\Lang::t('chapter.editor_italic'),
        'link' => \App\Lang::t('chapter.editor_link'),
        'quote' => \App\Lang::t('chapter.editor_quote'),
        'break' => \App\Lang::t('chapter.editor_break'),
    ], JSON_UNESCAPED_UNICODE)) ?>"><?= $this->e($story['content'] ?? '') ?></textarea></label>
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
