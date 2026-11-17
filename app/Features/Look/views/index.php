<?php // app/Features/Look/views/index.php ?>
<?php $this->layout('layout'); ?>
<?php
$t = static fn (string $k, array $p = []): string => \App\Lang::t($k, $p);
$style = static function (array $vars): string {
    $out = '';
    foreach ($vars as $name => $value) $out .= $name . ':' . $value . ';';
    return $out;
};
$colorFields = ['accent' => 'look.accent', 'bg' => 'look.bg', 'ink' => 'look.ink'];
$presetFields = ['width' => 'look.width', 'size' => 'look.size', 'density' => 'look.density', 'header' => 'look.header'];
?>
<h1><?= $t('look.heading') ?></h1>
<p class="lede"><?= $t('look.intro') ?></p>
<?php if ($errors !== []): ?>
<div class="form-error" role="alert">
  <p><?= $t('look.err_heading') ?></p>
  <ul>
    <?php foreach ($errors as $message): ?><li><?= $this->e($message) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<form method="post" action="/look/save" class="form-card look-form" data-js-module="look" data-preview-url="<?= $this->e($previewUrl) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <h2 class="form-card-title"><?= $t('look.colors') ?></h2>
  <?php foreach ($colorFields as $field => $label): ?>
  <label><?= $t($label) ?>
    <input type="color" <?= 'name="' . $field . '" value="' . $this->e($values[$field]) . '"' ?> pattern="#[0-9a-fA-F]{6}" required>
  </label>
  <?php endforeach; ?>
  <h2 class="form-card-title"><?= $t('look.layout') ?></h2>
  <?php foreach ($presetFields as $field => $label): ?>
  <label><?= $t($label) ?>
    <select name="<?= $field ?>">
      <?php foreach (\App\Look\Presets::names($field) as $name): ?>
      <option value="<?= $this->e($name) ?>"<?= $values[$field] === $name ? ' selected' : '' ?>><?= $t('look.opt.' . $field . '.' . $name) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endforeach; ?>
  <button type="submit" class="btn-primary"><?= $t('look.save') ?></button>
  <button type="submit" class="btn-secondary" formaction="/look/preview" data-look-preview-button><?= $t('look.preview') ?></button>
</form>
<h2><?= $t('look.preview') ?></h2>
<p class="chapter-meta"><?= $t('look.preview_note') ?></p>
<div class="look-panes">
  <?php foreach (['light' => 'look.preview_light', 'night' => 'look.preview_night'] as $pane => $label): ?>
  <section class="look-preview" data-look-pane="<?= $pane ?>" aria-label="<?= $this->e($t($label)) ?>" style="<?= $this->e($style($panes[$pane])) ?>"
    data-width="<?= $this->e($presets['width']) ?>" data-size="<?= $this->e($presets['size']) ?>"
    data-density="<?= $this->e($presets['density']) ?>" data-header="<?= $this->e($presets['header']) ?>">
    <div class="look-preview-head"><?= $t($label) ?></div>
    <div class="look-preview-body">
      <h3><?= $t('look.sample_heading') ?></h3>
      <p><?= $t('look.sample_text') ?> <a href="#" tabindex="-1"><?= $t('look.sample_link') ?></a></p>
      <p><span class="look-preview-button"><?= $t('look.sample_button') ?></span></p>
      <span class="look-preview-field"><?= $t('look.sample_field') ?></span>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<form method="post" action="/look/reset" class="inline" data-js-module="confirm" data-confirm="<?= $this->e($t('look.confirm_reset')) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button type="submit"><?= $t('look.reset') ?></button>
</form>
