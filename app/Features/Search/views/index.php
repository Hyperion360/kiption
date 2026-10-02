<?php // app/views/search/index.php ?>
<?php // Search (undesigned in the comps; built from the Kip blog/admin
     // vocabulary): a page head, one large query row with the submit beside
     // it, the filters as a quiet "Refine" group, results as the M6 story
     // cards (the shared _story_cards partial), and the outline pager. A bare
     // visit with nothing asked shows a hint, not an empty-result message.
     $this->layout('layout');
     $asked = $q !== '' || array_filter($filters, static fn ($v): bool => $v !== null && $v !== '' && $v !== false) !== [];
     $refined = ($filters['category'] ?? null) !== null || ($filters['rating_id'] ?? null) !== null
         || ($filters['completed'] ?? null) === true || (string) ($filters['language'] ?? '') !== ''; ?>
<div class="page page-search">
  <header class="page-head">
    <h1><?= \App\Lang::t('search.heading') ?></h1>
    <p class="lede"><?= \App\Lang::t('search.lede') ?></p>
  </header>
  <form method="get" action="/search" class="search-form" role="search">
    <div class="search-row">
      <label class="search-q"><span class="visually-hidden"><?= \App\Lang::t('search.query') ?></span><input type="search" name="q" maxlength="200" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('search.placeholder')) ?>"></label>
      <button type="submit" class="btn-primary"><?= \App\Lang::t('search.submit') ?></button>
    </div>
    <details class="search-refine"<?= $refined ? ' open' : '' ?>>
      <summary><?= \App\Lang::t('search.refine') ?></summary>
      <div class="refine-grid">
        <label><?= \App\Lang::t('search.category') ?>
          <select name="category">
            <option value=""><?= \App\Lang::t('search.any_category') ?></option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= $this->e($c['slug']) ?>" <?= ($filters['category'] ?? null) === $c['slug'] ? 'selected' : '' ?>><?= $this->e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('search.rating') ?>
          <select name="rating">
            <option value=""><?= \App\Lang::t('search.any_rating') ?></option>
            <?php foreach ($ratings as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) ($filters['rating_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= $this->e($r['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('common.language_hint') ?> <input name="language" maxlength="10" value="<?= $this->e($filters['language'] ?? '') ?>"></label>
        <label class="inline"><input type="checkbox" name="completed" value="1" <?= ($filters['completed'] ?? null) === true ? 'checked' : '' ?>> <?= \App\Lang::t('search.completed_only') ?></label>
      </div>
    </details>
  </form>
<?php if (!empty($result['capped'])): ?><p class="notice"><?= \App\Lang::t('search.capped') ?></p><?php endif; ?>
<?php if ($result['rows'] === []): ?>
  <p class="empty-state"><?= $asked ? \App\Lang::t('search.no_match') : \App\Lang::t('search.hint') ?></p>
<?php else: ?>
  <h2 class="section-label"><?= $q !== '' ? \App\Lang::t('search.results_for', ['q' => $this->e($q)]) : \App\Lang::t('search.results') ?></h2>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $result['rows']]) ?>
  </ul>
<?php
// Pagination preserves the active query string (q + every filter), the
// plan's "prev/next preserve the query string" requirement.
$carry = ['q' => $q,
          'category' => (string) ($filters['category'] ?? ''),
          'rating' => (string) ($filters['rating_id'] ?? ''),
          'completed' => ($filters['completed'] ?? null) === true ? '1' : '',
          'language' => (string) ($filters['language'] ?? '')];
$keep = array_filter($carry, fn (string $v): bool => $v !== '');
$prevQ = http_build_query($keep + ['page' => max(1, $page - 1)]);
$nextQ = http_build_query($keep + ['page' => $page + 1]);
?>
<?php if ($page > 1 || $result['hasMore']): ?>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
  <?php if ($page > 1): ?><a href="<?= $this->e('/search?' . $prevQ) ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
  <?php if ($result['hasMore']): ?><a class="pager-next" href="<?= $this->e('/search?' . $nextQ) ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</div>
