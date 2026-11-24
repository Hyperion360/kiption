<?php // app/Features/Browse/views/filter.php ?>
<?php // The filter page: a plain GET form that works with no scripting. The
     // tag lists sit in <details> blocks; the listfilter module (optional)
     // adds a narrowing box above them. Selected tags that the picker did
     // not list (it shows a capped list per type) ride in "Also Selected" so
     // a shared link never loses a choice. ?>
<?php $this->layout('layout');
     $statusLabels = ['any' => 'browse.status_any', 'complete' => 'browse.status_complete', 'wip' => 'browse.status_wip'];
     $sortLabels = ['updated' => 'browse.sort_updated', 'created' => 'browse.sort_created', 'words' => 'browse.sort_words', 'title' => 'browse.sort_title'];
     $pageHref = static fn (int $p): string => '/browse/filter?' . http_build_query($q->carry() + ['page' => $p]); ?>
<h1><?= \App\Lang::t('browse.filter_title') ?></h1>
<form method="get" action="/browse/filter" class="filter-form">
<?= $this->render('browse/_tag_picker', ['result' => $result, 'tagFilter' => $q->tags]) ?>
  <div class="refine-grid">
    <label><?= \App\Lang::t('browse.status') ?>
      <select name="status">
        <?php foreach ($statusLabels as $v => $k): ?><option value="<?= $v ?>"<?= $q->status === $v ? ' selected' : '' ?>><?= \App\Lang::t($k) ?><?php if ($v !== 'any'): ?> (<?= $this->e((string) (int) $result['counts']['status'][$v === 'complete' ? 1 : 0]) ?>)<?php endif; ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('browse.language') ?>
      <select name="language">
        <option value=""><?= \App\Lang::t('browse.any_language') ?></option>
        <?php $languages = $result['languages']; if ($q->language !== '' && !in_array($q->language, $languages, true)) $languages[] = $q->language; ?>
        <?php foreach ($languages as $l): ?><option value="<?= $this->e($l) ?>"<?= $q->language === $l ? ' selected' : '' ?>><?= $this->e($l) ?> (<?= $this->e((string) (int) ($result['counts']['language'][$l] ?? 0)) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <?php if (\App\Settings::on('categories_on')): ?>
    <fieldset>
      <legend><?= \App\Lang::t('search.categories') ?></legend>
      <p class="hint"><?= \App\Lang::t('search.category_counts_help') ?></p>
      <?php foreach ($result['categories'] as $c): ?>
      <label class="inline"><input type="checkbox" name="cat[]" value="<?= $this->e($c['slug']) ?>"<?= in_array($c['slug'], $q->categories, true) ? ' checked' : '' ?>> <?= $this->e($c['name']) ?> (<?= $this->e((string) (int) $c['count']) ?>)</label>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>
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
<h2 class="section-label"><?= \App\Lang::t($result['total'] === 1 ? 'search.matching_story' : 'search.matching_stories', ['count' => $this->e((string) (int) $result['total'])]) ?></h2>
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
