<?php
     $tagIds = array_map('intval', $tagFilter->include);
     $xIds = array_map('intval', $tagFilter->exclude);
     $listed = [];
     foreach ($result['tags'] as $t) { $listed[(int) $t['id']] = true; }
     $extra = array_values(array_filter($result['selected'], static fn (array $t): bool => !isset($listed[(int) $t['id']])));
?>
<?php $rail = !empty($rail); /* the search rail: chips on top, this checkbox pair as the no-script fallback */ ?>
<?php if ($rail): ?>
  <?php /* Shown by the tagchips module only. */ ?>
  <p class="tag-find" hidden data-tag-find>
    <label><span class="visually-hidden"><?= \App\Lang::t('browse.tag_search') ?></span><input type="search" autocomplete="off" placeholder="<?= $this->e(\App\Lang::t('search.find_tag')) ?>"></label>
    <span class="chapter-meta" data-tag-none role="status" hidden><?= \App\Lang::t('browse.tag_none') ?></span>
  </p>
  <div class="tag-chips" data-tag-chips hidden data-state-include="<?= $this->e(\App\Lang::t('search.tag_state_include')) ?>" data-state-exclude="<?= $this->e(\App\Lang::t('search.tag_state_exclude')) ?>" data-state-off="<?= $this->e(\App\Lang::t('search.tag_state_off')) ?>"></div>
<div class="tag-fallback" data-tag-fallback>
<p class="hint"><?= \App\Lang::t('search.tag_counts_help') ?></p>
<?php else: ?>
<p class="hint"><?= \App\Lang::t('search.tag_counts_help') ?></p>
  <?php /* Shown by the listfilter module only. */ ?>
  <p class="live-filter" hidden data-js-module="listfilter" data-filter-rows=".tag-pick">
    <label><?= \App\Lang::t('browse.tag_search') ?> <input type="search" autocomplete="off"></label>
    <span class="chapter-meta" data-filter-none role="status" hidden><?= \App\Lang::t('browse.tag_none') ?></span>
  </p>
<?php endif; ?>
  <?php foreach ([['browse.include_tags', 'tag', $tagIds], ['browse.exclude_tags', 'xtag', $xIds]] as [$label, $field, $on]): ?>
  <details class="search-refine"<?= $on !== [] ? ' open' : '' ?>>
    <summary><?= \App\Lang::t($label) ?></summary>
    <?php foreach ($result['types'] as $type): ?>
    <fieldset>
      <legend><?= $this->e($type['name']) ?></legend>
      <?php foreach ($result['tags'] as $t): if ((int) $t['type'] !== (int) $type['id']) continue; ?>
      <label class="tag-pick" data-count="<?= (int) $t['count'] ?>" data-xcount="<?= (int) $t['excludedCount'] ?>"><input type="checkbox" <?= 'name="' . $field . '[]" value="' . (int) $t['id'] . '"' . (in_array((int) $t['id'], $on, true) ? ' checked' : '') ?>> <span data-search-count="<?= $field ?>:<?= (int) $t['id'] ?>" data-count-label="<?= $this->e($t['name']) ?>"><?= $this->e($t['name']) ?> (<?= $this->e((string) (int) $t[$field === 'xtag' ? 'excludedCount' : 'count']) ?>)</span></label>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
  </details>
  <?php endforeach; ?>
  <?php if ($extra !== []): ?>
  <fieldset>
    <legend><?= \App\Lang::t('browse.also_selected') ?></legend>
    <?php foreach ($extra as $t): $isX = in_array((int) $t['id'], $xIds, true); ?>
    <label class="tag-pick"><input type="checkbox" <?= 'name="' . ($isX ? 'xtag' : 'tag') . '[]" value="' . (int) $t['id'] . '" checked' ?>> <span data-search-count="<?= $isX ? 'xtag' : 'tag' ?>:<?= (int) $t['id'] ?>" data-count-label="<?= $this->e($t['name']) ?>"><?= $this->e($t['name']) ?> (<?= $this->e((string) (int) $t[$isX ? 'excludedCount' : 'count']) ?>)</span></label>
    <?php endforeach; ?>
  </fieldset>
  <?php endif; ?>
<?php if ($rail): ?></div><?php endif; ?>
