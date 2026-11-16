<?php // app/Features/Browse/views/filter.php ?>
<?php // The filter page: a plain GET form that works with no scripting. The
     // tag lists sit in <details> blocks; the listfilter module (optional)
     // adds a narrowing box above them. Selected tags that the picker did
     // not list (it shows a capped list per type) ride in "Also Selected" so
     // a shared link never loses a choice. ?>
<?php $this->layout('layout');
     $tagIds = array_map('intval', $q->tags->include);
     $xIds = array_map('intval', $q->tags->exclude);
     $listed = [];
     foreach ($result['tags'] as $t) { $listed[(int) $t['id']] = true; }
     $extra = array_values(array_filter($result['selected'], static fn (array $t): bool => !isset($listed[(int) $t['id']])));
     $statusLabels = ['any' => 'browse.status_any', 'complete' => 'browse.status_complete', 'wip' => 'browse.status_wip'];
     $sortLabels = ['updated' => 'browse.sort_updated', 'created' => 'browse.sort_created', 'words' => 'browse.sort_words', 'title' => 'browse.sort_title'];
     $pageHref = static fn (int $p): string => '/browse/filter?' . http_build_query($q->carry() + ['page' => $p]); ?>
<h1><?= \App\Lang::t('browse.filter_title') ?></h1>
<form method="get" action="/browse/filter" class="filter-form">
  <?php /* Shown by the listfilter module only. */ ?>
  <p class="live-filter" hidden data-js-module="listfilter" data-filter-rows=".tag-pick">
    <label><?= \App\Lang::t('browse.tag_search') ?> <input type="search" autocomplete="off"></label>
    <span class="chapter-meta" data-filter-none role="status" hidden><?= \App\Lang::t('browse.tag_none') ?></span>
  </p>
  <?php foreach ([['browse.include_tags', 'tag', $tagIds], ['browse.exclude_tags', 'xtag', $xIds]] as [$label, $field, $on]): ?>
  <details class="search-refine"<?= $on !== [] ? ' open' : '' ?>>
    <summary><?= \App\Lang::t($label) ?></summary>
    <?php foreach ($result['types'] as $type): ?>
    <fieldset>
      <legend><?= $this->e($type['name']) ?></legend>
      <?php foreach ($result['tags'] as $t): if ((int) $t['type'] !== (int) $type['id']) continue; ?>
      <label class="tag-pick"><input type="checkbox" <?= 'name="' . $field . '[]" value="' . (int) $t['id'] . '"' . (in_array((int) $t['id'], $on, true) ? ' checked' : '') ?>> <?= $this->e($t['name']) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
  </details>
  <?php endforeach; ?>
  <?php if ($extra !== []): ?>
  <fieldset>
    <legend><?= \App\Lang::t('browse.also_selected') ?></legend>
    <?php foreach ($extra as $t): $isX = in_array((int) $t['id'], $xIds, true); ?>
    <label class="tag-pick"><input type="checkbox" <?= 'name="' . ($isX ? 'xtag' : 'tag') . '[]" value="' . (int) $t['id'] . '" checked' ?>> <?= $this->e($t['name']) ?></label>
    <?php endforeach; ?>
  </fieldset>
  <?php endif; ?>
  <div class="refine-grid">
    <label><?= \App\Lang::t('browse.status') ?>
      <select name="status">
        <?php foreach ($statusLabels as $v => $k): ?><option value="<?= $v ?>"<?= $q->status === $v ? ' selected' : '' ?>><?= \App\Lang::t($k) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('browse.language') ?>
      <select name="language">
        <option value=""><?= \App\Lang::t('browse.any_language') ?></option>
        <?php foreach ($result['languages'] as $l): ?><option value="<?= $this->e($l) ?>"<?= $q->language === $l ? ' selected' : '' ?>><?= $this->e($l) ?></option><?php endforeach; ?>
      </select>
    </label>
    <fieldset>
      <legend><?= \App\Lang::t('search.categories') ?></legend>
      <p class="hint"><?= \App\Lang::t('search.categories_hint') ?></p>
      <?php foreach ($result['categories'] as $c): ?>
      <label class="inline"><input type="checkbox" name="cat[]" value="<?= $this->e($c['slug']) ?>"<?= in_array($c['slug'], $q->categories, true) ? ' checked' : '' ?>> <?= $this->e($c['name']) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label><?= \App\Lang::t('browse.min_words') ?> <input type="number" name="wmin" min="0" inputmode="numeric" value="<?= $q->wmin !== null ? (int) $q->wmin : '' ?>"></label>
    <label><?= \App\Lang::t('browse.max_words') ?> <input type="number" name="wmax" min="0" inputmode="numeric" value="<?= $q->wmax !== null ? (int) $q->wmax : '' ?>"></label>
    <label><?= \App\Lang::t('browse.sort') ?>
      <select name="sort">
        <?php foreach ($sortLabels as $v => $k): ?><option value="<?= $v ?>"<?= $q->sort === $v ? ' selected' : '' ?>><?= \App\Lang::t($k) ?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="queue-actions">
    <button type="submit" class="btn-primary"><?= \App\Lang::t('browse.apply') ?></button>
    <a href="/browse/filter"><?= \App\Lang::t('browse.clear') ?></a>
  </div>
</form>
<?php if ($result['rows'] === []): ?>
  <p class="empty-state"><?= \App\Lang::t('browse.no_matches') ?></p>
<?php else: ?>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $result['rows']]) ?>
  </ul>
<?php endif; ?>
<?php if ($q->page > 1 || $result['hasMore']): ?>
<nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
  <?php if ($q->page > 1): ?><a rel="prev" href="<?= $this->e($pageHref($q->page - 1)) ?>"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
  <?php if ($result['hasMore']): ?><a rel="next" href="<?= $this->e($pageHref($q->page + 1)) ?>"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
</nav>
<?php endif; ?>
