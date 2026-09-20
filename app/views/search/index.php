<?php // app/views/search/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('search.heading') ?></h1>
<form method="get" action="/search">
  <label><?= \App\Lang::t('search.query') ?> <input type="search" name="q" maxlength="200" value="<?= $this->e($q) ?>"></label>
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
  <label class="inline"><input type="checkbox" name="completed" value="1" <?= ($filters['completed'] ?? null) === true ? 'checked' : '' ?>> <?= \App\Lang::t('search.completed_only') ?></label>
  <label><?= \App\Lang::t('common.language_hint') ?> <input name="language" maxlength="10" value="<?= $this->e($filters['language'] ?? '') ?>"></label>
  <button type="submit"><?= \App\Lang::t('search.submit') ?></button>
</form>
<?php if (!empty($result['capped'])): ?><p class="meta"><?= \App\Lang::t('search.capped') ?></p><?php endif; ?>
<?php if ($result['rows'] === []): ?>
<p class="meta"><?= \App\Lang::t('search.no_match') ?></p>
<?php else: ?>
<ul class="story-list">
<?php foreach ($result['rows'] as $s): ?>
  <li>
    <a href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <?= \App\Lang::t('story.by') ?> <?= $this->e($s['penname']) ?>
    <div class="meta">
      <?= $this->e($s['rating_label']) ?>
      <?php if ($s['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?>
      <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?>
      <?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $s['updated_at'], 0, 10))]) ?>
    </div>
    <p><?= $this->e($s['summary']) ?></p>
<?php endforeach; ?>
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
<?php if ($page > 1): ?><a href="<?= $this->e('/search?' . $prevQ) ?>"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
<?php if ($result['hasMore']): ?><a href="<?= $this->e('/search?' . $nextQ) ?>"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
<?php endif; ?>
