<?php // app/Features/Text/views/group.php ?>
<?php $this->layout('layout'); ?>
<?php $dir = 'ltr'; foreach ($languages as $l) { if ($l['code'] === $lang) { $dir = $l['dir']; } } ?>
<h1><?= $q !== '' ? $this->e(\App\Lang::t('text.search_results', ['q' => $q])) : $this->e($label ?? $prefix) ?></h1>
<p><a class="btn" href="<?= $this->e('/text?lang=' . $lang) ?>"><?= \App\Lang::t('text.back') ?></a></p>
<p class="chapter-meta"><?= \App\Lang::t('text.revert_note') ?></p>
<?php if ($entries === []): ?>
<p><?= \App\Lang::t('text.search_none') ?></p>
<?php else: ?>
<form method="post" action="/text/save" class="form-card">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <input type="hidden" name="lang" value="<?= $this->e($lang) ?>">
  <?php foreach ($entries as $e): ?>
  <label><code><?= $this->e($e['key']) ?></code><?= $e['changed'] ? ' *' : '' ?>
    <?php $field = ' name="' . $this->e('values[' . $e['key'] . ']') . '" dir="' . $this->e($dir) . '"'; ?>
    <?php if ($e['multiline']): ?>
    <textarea rows="3" maxlength="2000"<?= $field ?>><?= $this->e($e['value']) ?></textarea>
    <?php else: ?>
    <input maxlength="1000"<?= $field . ' value="' . $this->e($e['value']) . '"' ?>>
    <?php endif; ?>
  </label>
  <p class="chapter-meta"><?= $this->e(\App\Lang::t('text.original', ['text' => $e['original']])) ?>
    <?php if ($e['names'] !== []): ?><br><?= $this->e(\App\Lang::t('text.keeps', ['names' => '{' . implode('} {', $e['names']) . '}'])) ?><?php endif; ?></p>
  <?php endforeach; ?>
  <button type="submit" class="btn-primary"><?= \App\Lang::t('text.save') ?></button>
</form>
<?php endif; ?>
