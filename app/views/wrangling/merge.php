<?php // app/views/wrangling/merge.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('wrangling.merge_heading') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if ($synonym === null): ?>
  <form method="get" action="/wrangling/merge">
    <label><?= \App\Lang::t('wrangling.synonym_label') ?>
      <select name="synonym">
        <?php foreach ($allGroups as $typeName => $tags): ?>
        <optgroup label="<?= $this->e($typeName) ?>">
          <?php foreach ($tags as $t): ?><option value="<?= (int) $t['id'] ?>"><?= $this->e($t['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= \App\Lang::t('wrangling.choose') ?></button>
  </form>
  <?php else: ?>
  <p class="chapter-meta"><?= \App\Lang::t('wrangling.merge_help', ['synonym' => $this->e($synonym['name'])]) ?></p>
  <?php if ($candidates === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('wrangling.no_candidates') ?></p>
  <?php else: ?>
  <form method="post" action="/wrangling/merge">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <input type="hidden" name="synonym_id" value="<?= (int) $synonym['id'] ?>">
    <label><?= \App\Lang::t('wrangling.synonym_label') ?> <?= $this->e($synonym['name']) ?></label>
    <label><?= \App\Lang::t('wrangling.canonical_label') ?>
      <select name="canonical_id">
        <?php foreach ($candidates as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $this->e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <p class="chapter-meta"><?= \App\Lang::t('wrangling.unmerge_note') ?></p>
    <button type="submit"><?= \App\Lang::t('wrangling.merge_button') ?></button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
  <p><a href="/wrangling"><?= \App\Lang::t('wrangling.back') ?></a></p>
</section>
