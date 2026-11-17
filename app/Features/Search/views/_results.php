<?php $asked = $q !== '' || $result['mode'] !== 'none'; ?>
<?php if (!empty($result['capped'])): ?><p class="notice"><?= \App\Lang::t('search.capped') ?></p><?php endif; ?>
<?php if ($asked): ?><h2 class="section-label"><?= \App\Lang::t($result['total'] === 1 ? 'search.matching_story' : 'search.matching_stories', ['count' => $this->e((string) (int) $result['total'])]) ?></h2><?php endif; ?>
<?php $chips = $chips ?? []; ?>
<?php if ($chips !== []): ?>
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
    <p class="empty-state"><?= \App\Lang::t('search.hint') ?></p>
    <p class="hint"><?= \App\Lang::t('search.start_lede') ?></p>
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
  <p class="empty-state"><?= \App\Lang::t('search.no_match') ?></p>
  <?php if ($chips !== []): ?>
  <div class="nomatch-drop">
    <h2 class="section-label"><?= \App\Lang::t('search.nomatch_drop') ?></h2>
    <ul class="chip-list">
      <?php foreach ($chips as $c): ?><li class="chip"><a href="<?= $this->e($c['remove']) ?>"><?= $this->e(\App\Lang::t('search.drop', ['label' => $c['label']])) ?></a></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php else: ?>
  <ul class="story-list">
<?= $this->render('browse/_story_cards', ['stories' => $result['rows'], 'passageQuery' => $q]) ?>
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
foreach (['tag', 'xtag', 'tags', 'xtags'] as $key) {
    $value = $filters[$key] ?? null;
    if ($value !== null && $value !== '' && $value !== []) $keep[$key] = $value;
}
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
