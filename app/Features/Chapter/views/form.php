<?php // app/views/chapter/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $position === null ? \App\Lang::t('chapter.new') : \App\Lang::t('chapter.edit', ['n' => (int) $position]) ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('chapter.for') ?> <a href="/story/edit/<?= $this->e($story['slug']) ?>"><?= $this->e($story['story_title']) ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $position === null
      ? '/chapter/create/' . $this->e($story['slug'])
      : '/chapter/update/' . $this->e($story['slug']) . '/' . (int) $position ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('chapter.title') ?> <input name="title" maxlength="200" value="<?= $this->e($story['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('chapter.note_before') ?> <textarea name="notes_before" rows="2"><?= $this->e($story['notes_before'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('chapter.text') ?> <textarea name="content" rows="18" required><?= $this->e($story['content'] ?? '') ?></textarea></label>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.markdown_help') ?></p>
    <label><?= \App\Lang::t('chapter.note_after') ?> <textarea name="notes_after" rows="2"><?= $this->e($story['notes_after'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('chapter.publish_at') ?> <input type="datetime-local" name="publish_at" value="<?= $this->e(substr((string) ($story['publish_at'] ?? ''), 0, 16)) ?>"></label>
    <p class="chapter-meta"><?= \App\Lang::t('chapter.publish_hint') ?></p>
    <button type="submit"><?= \App\Lang::t('chapter.save') ?></button>
  </form>
  <?php if ($position !== null): ?>
    <form method="post" action="/chapter/delete/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit"><?= \App\Lang::t('chapter.delete') ?></button>
    </form>
  <?php endif; ?>
</section>
