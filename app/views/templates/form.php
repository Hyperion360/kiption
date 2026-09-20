<?php // app/views/templates/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('templates.edit_heading') ?></h1>
  <p><strong><?= $this->e($name) ?></strong> <a href="/templates"><?= \App\Lang::t('templates.back') ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/templates/update/<?= $this->e($name) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('templates.subject') ?> <input name="subject" required value="<?= $this->e($subject) ?>"></label>
    <label><?= \App\Lang::t('common.body') ?> <textarea name="body" rows="8"><?= $this->e($body) ?></textarea></label>
    <button type="submit"><?= \App\Lang::t('common.save') ?></button>
  </form>
  <?php if ($vocabulary !== []): ?>
  <h2><?= \App\Lang::t('templates.placeholders') ?></h2>
  <p class="chapter-meta"><?= \App\Lang::t('templates.placeholders_help') ?></p>
  <ul>
    <?php foreach ($vocabulary as $placeholder => $meaning): ?>
      <li><code><?= $this->e($placeholder) ?></code> <?= $this->e($meaning) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="chapter-meta"><?= \App\Lang::t('templates.no_placeholders') ?></p>
  <?php endif; ?>
</section>
