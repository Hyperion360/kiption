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
foreach (['tag', 'xtag', 'tags', 'xtags'] as $key) {
    $value = $filters[$key] ?? null;
    if ($value !== null && $value !== '' && $value !== []) $keep[$key] = $value;
}
$sortKeep = $keep; unset($sortKeep['sort']);
?>
<?php $asked = $q !== '' || $result['mode'] !== 'none'; ?>
<?php if (!empty($result['capped'])): ?><p class="notice"><?= \App\Lang::t('search.capped') ?></p><?php endif; ?>
<?php $state = !$asked ? 'start' : ((int) $result['total'] === 0 ? 'none' : 'results'); ?>
<span hidden data-search-state="<?= $state ?>"></span>
<?php if ($state === 'none'): ?>
<h2 class="visually-hidden"><?= \App\Lang::t('search.matching_stories', ['count' => '0']) ?></h2>
<?php endif; ?>
<?php if ($state === 'results'): ?>
<div class="results-head">
  <h2 class="section-label"><?= \App\Lang::t($result['total'] === 1 ? 'search.matching_story' : 'search.matching_stories', ['count' => $this->e((string) (int) $result['total'])]) ?></h2>
  <nav class="sort-toggle" aria-label="<?= $this->e(\App\Lang::t('search.sort')) ?>">
    <a href="<?= $this->e('/search?' . http_build_query($sortKeep)) ?>"<?= $sort !== 'updated' ? ' aria-current="true"' : '' ?>><?= \App\Lang::t('search.sort_relevance') ?></a>
    <a href="<?= $this->e('/search?' . http_build_query($sortKeep + ['sort' => 'updated'])) ?>"<?= $sort === 'updated' ? ' aria-current="true"' : '' ?>><?= \App\Lang::t('search.sort_updated') ?></a>
  </nav>
</div>
<?php endif; ?>
<?php $chips = $chips ?? []; ?>
<?php if ($chips !== [] && $state === 'results'): ?>
  <div class="active-filters" data-search-chips>
    <h3 class="visually-hidden"><?= \App\Lang::t('search.active_filters') ?></h3>
    <ul class="chip-list">
    <?php foreach ($chips as $c): ?>
      <li class="chip chip-<?= $this->e($c['kind']) ?>"><span class="chip-kind"><?= \App\Lang::t($c['kind'] === 'exclude' ? 'search.exclude_note' : 'search.include_note') ?>:</span> <?= $this->e($c['label']) ?>
        <a class="chip-x" href="<?= $this->e($c['remove']) ?>" aria-label="<?= $this->e(\App\Lang::t('search.remove_filter', ['label' => $c['label']])) ?>"><?= "×" ?></a></li>
    <?php endforeach; ?>
    </ul>
    <a class="chip-clear" href="/search<?= $q === '' ? '' : '?q=' . $this->e(rawurlencode($q)) ?>"><?= \App\Lang::t('search.clear_all') ?></a>
  </div>
<?php endif; ?>
<?php if ($result['rows'] === [] && !$asked): ?>
  <div class="search-start">
    <?php $popular = $result['tags']; usort($popular, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
          $popular = array_slice(array_filter($popular, static fn (array $t): bool => (int) $t['count'] > 0), 0, 8); ?>
    <?php if ($popular !== []): ?>
    <h2 class="section-label"><?= \App\Lang::t('search.start_popular') ?></h2>
    <ul class="chip-list">
      <?php foreach ($popular as $t): ?><li class="chip"><a href="/search?tag%5B%5D=<?= (int) $t['id'] ?>"><?= $this->e($t['name']) ?> (<?= (int) $t['count'] ?>)</a></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($result['categories'] !== []): ?>
    <h2 class="section-label"><?= \App\Lang::t('search.start_categories') ?></h2>
    <ul class="chip-list">
      <?php foreach ($result['categories'] as $c): ?><li class="chip"><a href="/search?category%5B%5D=<?= $this->e(rawurlencode($c['slug'])) ?>"><?= $this->e($c['name']) ?> (<?= (int) $c['count'] ?>)</a></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php elseif ($result['rows'] === []): ?>
<?php
  // The one statement also counted what each single dropped filter would bring back.
  $drops = $result['drops'] ?? [];
  $mentions = $drops === [] ? null : ($drops[count($drops) - 1] ?? null);
  $options = [];
  foreach ($chips as $i => $c) {
      $n = $drops[$i] ?? null;
      if ($n !== null && $n > 0) $options[] = ['label' => $c['label'], 'n' => $n, 'href' => $c['remove']];
  }
  $count = static fn (int $n): string => \App\Lang::t($n === 1 ? 'search.stories_one' : 'search.stories_n', ['count' => number_format($n)]);
  $clearHref = '/search' . ($q === '' ? '' : '?q=' . rawurlencode($q));
?>
  <div class="nomatch">
  <?php if ($chips === []): ?>
    <p class="empty-state"><?= \App\Lang::t('search.no_match') ?></p>
  <?php else: ?>
    <h2 class="nomatch-title"><?= \App\Lang::t('search.nomatch_title') ?></h2>
    <?php $filterText = \App\Search\ActiveFilters::phrase($chips); $qe = $this->e($q); ?>
    <?php if ($q === ''): ?>
    <p class="nomatch-lede"><?= \App\Lang::t('search.nomatch_filters', ['filters' => $filterText]) ?></p>
    <?php elseif ($mentions === 0): ?>
    <p class="nomatch-lede"><?= \App\Lang::t('search.nomatch_none', ['q' => $qe]) ?></p>
    <?php else: ?>
    <p class="nomatch-lede"><?= \App\Lang::t($mentions === 1 ? 'search.nomatch_mention_one' : 'search.nomatch_mention', ['count' => number_format((int) $mentions), 'q' => $qe, 'filters' => $filterText]) ?></p>
    <?php endif; ?>
    <?php if ($options !== [] || ($mentions ?? 0) > 0): ?>
    <ul class="nomatch-options">
      <?php foreach ($options as $o): ?>
      <li><a class="nomatch-option" href="<?= $this->e($o['href']) ?>"><span class="nomatch-what"><?= \App\Lang::t('search.without', ['label' => '<strong>' . $this->e($o['label']) . '</strong>']) ?></span> <span class="nomatch-n"><?= $this->e($count((int) $o['n'])) ?></span> <span class="nomatch-go" aria-hidden="true"><?= \App\Lang::t('search.arrow_go') ?></span></a></li>
      <?php endforeach; ?>
      <?php if (count($chips) > 1 && ($mentions ?? 0) > 0): ?>
      <li><a class="nomatch-option" href="<?= $this->e($clearHref) ?>"><span class="nomatch-what"><?= \App\Lang::t('search.clear_every') ?></span> <span class="nomatch-n"><?= $this->e($count((int) $mentions)) ?></span> <span class="nomatch-go" aria-hidden="true"><?= \App\Lang::t('search.arrow_go') ?></span></a></li>
      <?php endif; ?>
    </ul>
    <?php endif; ?>
  <?php endif; ?>
    <p class="nomatch-mood"><?= \App\Lang::t('search.mood_lede') ?> <a href="/browse/filter"><?= \App\Lang::t('search.try_browse') ?></a></p>
  </div>
<?php else: ?>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $result['rows'], 'passageQuery' => $q, 'searchCard' => true]) ?>
  </ul>
<?php
$prevQ = http_build_query($keep + ['page' => max(1, $page - 1)]);
$nextQ = http_build_query($keep + ['page' => $page + 1]);
?>
<?php $pages = max($page, (int) ceil(((int) $result['total']) / max(1, $perPage))); ?>
<?php if ($page > 1 || $result['hasMore']): ?>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
    <span class="pager-count"><?= \App\Lang::t('search.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
    <span class="pager-links">
    <?php if ($page > 1): ?><a href="<?= $this->e('/search?' . $prevQ) ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php else: ?><span class="pager-off" aria-disabled="true"><?= \App\Lang::t('common.previous') ?></span><?php endif; ?>
    <?php if ($result['hasMore']): ?><a class="pager-next" href="<?= $this->e('/search?' . $nextQ) ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php else: ?><span class="pager-off" aria-disabled="true"><?= \App\Lang::t('common.next') ?></span><?php endif; ?>
    </span>
  </nav>
<?php endif; ?>
<?php endif; ?>
