<?php // app/views/search/index.php ?>
<?php // Search (undesigned in the comps; built from the Kip blog/admin
     // vocabulary): a page head, one large query row with the submit beside
     // it, the filters as a quiet "Refine" group, results as the M6 story
     // cards (the shared _story_cards partial), and the outline pager. A bare
     // visit with nothing asked shows a hint, not an empty-result message.
     $this->layout('layout');
     $asked = $q !== '' || array_filter($filters, static fn ($v): bool => $v !== null && $v !== '' && $v !== []) !== [];
     $refined = ($filters['category'] ?? []) !== [] || ($filters['rating_id'] ?? null) !== null
         || ($filters['completed'] ?? null) !== null || (string) ($filters['language'] ?? '') !== ''
         || ($filters['tag'] ?? null) !== null || ($filters['character'] ?? null) !== null
         || (string) ($filters['tags'] ?? '') !== '' || (string) ($filters['xtags'] ?? '') !== ''
         || ($filters['xtag'] ?? null) !== null; ?>
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
        <fieldset>
          <legend><?= \App\Lang::t('search.categories') ?></legend>
          <p class="hint"><?= \App\Lang::t('search.category_counts_help') ?></p>
          <?php foreach ($categories as $c): ?>
          <label class="inline"><input type="checkbox" name="category[]" value="<?= $this->e($c['slug']) ?>"<?= in_array($c['slug'], $filters['category'] ?? [], true) ? ' checked' : '' ?>> <?= $this->e($c['name']) ?> (<?= $this->e((string) (int) $c['count']) ?>)</label>
          <?php endforeach; ?>
        </fieldset>
        <label><?= \App\Lang::t('search.rating') ?>
          <select name="rating">
            <option value=""><?= \App\Lang::t('search.any_rating') ?></option>
            <?php foreach ($ratings as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) ($filters['rating_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= $this->e($r['label']) ?> (<?= $this->e((string) (int) $r['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('browse.language') ?>
          <select name="language">
            <option value=""><?= \App\Lang::t('browse.any_language') ?></option>
            <?php $languages = $result['languages']; $language = (string) ($filters['language'] ?? '');
                if ($language !== '' && !in_array($language, $languages, true)) $languages[] = $language; ?>
            <?php foreach ($languages as $l): ?><option value="<?= $this->e($l) ?>"<?= $language === $l ? ' selected' : '' ?>><?= $this->e($l) ?> (<?= $this->e((string) (int) ($result['counts']['language'][$l] ?? 0)) ?>)</option><?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('browse.status') ?>
          <select name="completed">
            <option value=""><?= \App\Lang::t('browse.status_any') ?></option>
            <?php foreach ([1 => 'browse.status_complete', 0 => 'browse.status_wip'] as $value => $label): ?>
            <option value="<?= $value ?>"<?= ($filters['completed'] ?? null) === (bool) $value ? ' selected' : '' ?>><?= \App\Lang::t($label) ?> (<?= $this->e((string) (int) $result['counts']['status'][$value]) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('search.tags') ?> <input name="tags" maxlength="520" value="<?= $this->e((string) ($filters['tags'] ?? '')) ?>"></label>
        <label><?= \App\Lang::t('search.xtags') ?> <input name="xtags" maxlength="520" value="<?= $this->e((string) ($filters['xtags'] ?? '')) ?>"></label>
        <label><?= \App\Lang::t('search.sort') ?>
          <select name="sort">
            <option value="" <?= ($sort ?? 'relevance') !== 'updated' ? 'selected' : '' ?>><?= \App\Lang::t('search.sort_relevance') ?></option>
            <option value="updated" <?= ($sort ?? 'relevance') === 'updated' ? 'selected' : '' ?>><?= \App\Lang::t('search.sort_updated') ?></option>
          </select>
        </label>
        <p class="hint"><?= \App\Lang::t('search.tags_hint') ?></p>
        <p class="hint"><a href="/browse/filter<?php
            $browseQ = http_build_query(array_filter([
                'tag' => $filters['tag'] ?? null,
                'xtag' => $filters['xtag'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []));
            echo $browseQ === '' ? '' : '?' . $this->e($browseQ); ?>"><?= \App\Lang::t('search.try_browse') ?></a></p>
        <?php if (($filters['character'] ?? null) !== null): ?>
        <input type="hidden" name="character" value="<?= (int) $filters['character'] ?>"><p class="hint"><?= \App\Lang::t('search.filtered_character') ?> <a href="/search?<?= $this->e(http_build_query(array_filter(['q' => $q, 'tag' => $filters['tag'] ?? '']))) ?>"><?= \App\Lang::t('search.clear_filter') ?></a></p>
        <?php endif; ?>
      </div>
<?= $this->render('browse/_tag_picker', ['result' => $result, 'tagFilter' => \App\Repositories\TagFilter::fromQuery($filters)]) ?>
    </details>
  </form>
<?php if (!empty($result['capped'])): ?><p class="notice"><?= \App\Lang::t('search.capped') ?></p><?php endif; ?>
<?php if ($asked): ?><h2 class="section-label"><?= \App\Lang::t($result['total'] === 1 ? 'search.matching_story' : 'search.matching_stories', ['count' => $this->e((string) (int) $result['total'])]) ?></h2><?php endif; ?>
<?php if ($result['rows'] === []): ?>
  <p class="empty-state"><?= $asked ? \App\Lang::t('search.no_match') : \App\Lang::t('search.hint') ?></p>
<?php else: ?>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $result['rows']]) ?>
  </ul>
<?php
// Pagination preserves the active query string (q + every filter), the
// plan's "prev/next preserve the query string" requirement.
$carry = ['q' => $q,
          'category' => $filters['category'] ?? [],
          'rating' => (string) ($filters['rating_id'] ?? ''),
          'completed' => ($filters['completed'] ?? null) === null ? '' : ($filters['completed'] ? '1' : '0'),
          'language' => (string) ($filters['language'] ?? ''),
          'character' => (string) ($filters['character'] ?? ''), 'sort' => $sort];
$keep = array_filter($carry, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
// Tag ids ride the pager in the shape they arrived (one id or several), or
// paging drops them. Leave-out ids and tag names ride too.
$tag = $filters['tag'] ?? null;
if (is_array($tag)) {
    $tag = array_values(array_filter($tag, fn ($v): bool => is_string($v) || is_int($v)));
    $tag = array_values(array_filter($tag, fn ($v): bool => (string) $v !== '' && ctype_digit((string) $v)));
    if ($tag !== []) { $keep['tag'] = $tag; }
} elseif ($tag !== null) { $keep['tag'] = (string) $tag; }
$xtag = array_values(array_filter((array) ($filters['xtag'] ?? []), 'is_string'));
$xtag = array_values(array_filter($xtag, 'ctype_digit'));
if ($xtag !== []) { $keep['xtag'] = $xtag; }
if (is_string($filters['tags'] ?? null) && $filters['tags'] !== '') { $keep['tags'] = $filters['tags']; }
if (is_string($filters['xtags'] ?? null) && $filters['xtags'] !== '') { $keep['xtags'] = $filters['xtags']; }
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
