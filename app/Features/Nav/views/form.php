<?php // app/views/nav/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('navlinks.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('navlinks.intro') ?></p>
  <?php if ($rows === []): ?><p class="chapter-meta"><?= \App\Lang::t('navlinks.none') ?></p><?php else: ?>
  <ul class="admin-rows">
    <?php foreach ($rows as $r): ?>
      <li>
        <strong><?= $this->e($r['label']) ?></strong><?= (int) $r['is_hidden'] === 1 ? ' ' . \App\Lang::t('navlinks.hidden') : '' ?>
        <span class="chapter-meta"><?= $this->e($r['url']) ?><?= \App\Lang::t('navlinks.position', ['n' => (int) $r['position']]) ?></span>
        <div class="queue-actions">
        <a class="btn" href="/nav/edit/<?= (int) $r['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
        <form method="post" action="/nav/delete/<?= (int) $r['id'] ?>" class="inline" data-js-module="confirm" data-confirm="<?= $this->e(\App\Lang::t('navlinks.confirm_delete')) ?>">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('common.delete') ?></button>
        </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= $row === null ? \App\Lang::t('navlinks.new') : \App\Lang::t('navlinks.edit') ?></h2>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" class="form-card" action="<?= $row === null ? '/nav/create' : '/nav/update/' . (int) $row['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('navlinks.label') ?> <input name="label" required maxlength="40" value="<?= $this->e($row['label'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('navlinks.url') ?> <input name="url" required value="<?= $this->e($row['url'] ?? '') ?>" placeholder="/page/view/about"> <span class="field-hint"><?= \App\Lang::t('navlinks.url_hint') ?></span></label>
    <label><?= \App\Lang::t('navlinks.position_label') ?> <input name="position" inputmode="numeric" value="<?= $this->e((string) ($row['position'] ?? '')) ?>"> <span class="field-hint"><?= \App\Lang::t('navlinks.position_hint') ?></span></label>
    <label><input type="checkbox" name="is_hidden" value="1"<?= (int) ($row['is_hidden'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('navlinks.hidden_label') ?></label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
</section>
