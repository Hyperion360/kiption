<?php $asked = $q !== '' || $result['mode'] !== 'none'; ?>
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
