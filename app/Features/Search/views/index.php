<?php // app/views/search/index.php ?>
<?php // Search (undesigned in the comps; built from the Kip blog/admin
     // vocabulary): a page head, one large query row with the submit beside
     // it, the filters as a quiet "Refine" group, results as the M6 story
     // cards (the shared _story_cards partial), and the outline pager. A bare
     // visit with nothing asked shows a hint, not an empty-result message.
     $this->layout('layout');
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
  <form method="get" action="/search" class="search-form" role="search" data-js-module="search"
    data-search-loading="<?= $this->e(\App\Lang::t('search.live_loading')) ?>"
    data-search-error="<?= $this->e(\App\Lang::t('search.live_error')) ?>"
    data-search-suggestions-label="<?= $this->e(\App\Lang::t('search.suggestions')) ?>">
    <div class="search-row">
      <label class="search-q"><span class="visually-hidden"><?= \App\Lang::t('search.query') ?></span><input type="search" name="q" aria-describedby="search-status" maxlength="200" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('search.placeholder')) ?>"></label>
      <button type="submit" class="btn-primary"><?= \App\Lang::t('search.submit') ?></button>
    </div>
    <ul id="search-suggestions" data-search-suggestions hidden></ul>
    <p id="search-status" data-search-status class="hint" role="status" aria-live="polite" aria-atomic="true"></p>
    <details class="search-refine"<?= $refined ? ' open' : '' ?>>
      <summary><?= \App\Lang::t('search.refine') ?></summary>
      <div class="refine-grid">
        <fieldset>
          <legend><?= \App\Lang::t('search.categories') ?></legend>
          <p class="hint"><?= \App\Lang::t('search.category_counts_help') ?></p>
          <?php foreach ($categories as $c): ?>
          <label class="inline"><input type="checkbox" name="category[]" value="<?= $this->e($c['slug']) ?>"<?= in_array($c['slug'], $filters['category'] ?? [], true) ? ' checked' : '' ?>> <span data-search-count="category:<?= $this->e($c['slug']) ?>" data-count-label="<?= $this->e($c['name']) ?>"><?= $this->e($c['name']) ?> (<?= $this->e((string) (int) $c['count']) ?>)</span></label>
          <?php endforeach; ?>
        </fieldset>
        <label><?= \App\Lang::t('search.rating') ?>
          <select name="rating">
            <option value=""><?= \App\Lang::t('search.any_rating') ?></option>
            <?php foreach ($ratings as $r): ?>
              <option value="<?= (int) $r['id'] ?>" data-search-count="rating:<?= (int) $r['id'] ?>" data-count-label="<?= $this->e($r['label']) ?>" <?= (int) ($filters['rating_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= $this->e($r['label']) ?> (<?= $this->e((string) (int) $r['count']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('browse.language') ?>
          <select name="language">
            <option value=""><?= \App\Lang::t('browse.any_language') ?></option>
            <?php $languages = $result['languages']; $language = (string) ($filters['language'] ?? '');
                if ($language !== '' && !in_array($language, $languages, true)) $languages[] = $language; ?>
            <?php foreach ($languages as $l): ?><option value="<?= $this->e($l) ?>" data-search-count="language:<?= $this->e($l) ?>" data-count-label="<?= $this->e($l) ?>"<?= $language === $l ? ' selected' : '' ?>><?= $this->e($l) ?> (<?= $this->e((string) (int) ($result['counts']['language'][$l] ?? 0)) ?>)</option><?php endforeach; ?>
          </select>
        </label>
        <label><?= \App\Lang::t('browse.status') ?>
          <select name="completed">
            <option value=""><?= \App\Lang::t('browse.status_any') ?></option>
            <?php foreach ([1 => 'browse.status_complete', 0 => 'browse.status_wip'] as $value => $label): ?>
            <option value="<?= $value ?>" data-search-count="status:<?= $value ?>" data-count-label="<?= $this->e(\App\Lang::t($label)) ?>"<?= ($filters['completed'] ?? null) === (bool) $value ? ' selected' : '' ?>><?= \App\Lang::t($label) ?> (<?= $this->e((string) (int) $result['counts']['status'][$value]) ?>)</option>
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
<?= $this->render('browse/_tag_picker', ['result' => $result, 'tagFilter' => $filters['tag_filter']]) ?>
    </details>
  </form>
<div id="search-results" data-search-results><?= $this->render('search/_results', compact('q', 'filters', 'result', 'sort', 'page', 'perPage')) ?></div>
</div>
