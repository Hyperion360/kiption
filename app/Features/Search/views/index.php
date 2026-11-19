<?php // app/views/search/index.php ?>
<?php // Search (undesigned in the comps; built from the Kip blog/admin
     // vocabulary): a page head, one large query row with the submit beside
     // it, the filters as a quiet "Refine" group, results as the M6 story
     // cards (the shared _story_cards partial), and the outline pager. A bare
     // visit with nothing asked shows a hint, not an empty-result message.
     $this->layout('layout'); ?>
<div class="page page-search">
  <header class="page-head">
    <h1><?= \App\Lang::t('search.heading') ?></h1>
  </header>
  <form method="get" action="/search" class="search-form" role="search" data-js-module="search"
    data-search-loading="<?= $this->e(\App\Lang::t('search.live_loading')) ?>"
    data-search-error="<?= $this->e(\App\Lang::t('search.live_error')) ?>"
    data-search-suggestions-label="<?= $this->e(\App\Lang::t('search.suggestions')) ?>"
    data-group-stories="<?= $this->e(\App\Lang::t('search.suggest_stories')) ?>"
    data-group-authors="<?= $this->e(\App\Lang::t('search.suggest_authors')) ?>"
    data-group-tags="<?= $this->e(\App\Lang::t('search.suggest_tags')) ?>"
    data-kbd-enter="<?= $this->e(\App\Lang::t('search.kbd_enter', ['q' => '{q}'])) ?>"
    data-kbd-choose="<?= $this->e(\App\Lang::t('search.kbd_choose')) ?>"
    data-kbd-close="<?= $this->e(\App\Lang::t('search.kbd_close')) ?>">
    <div class="search-row">
      <div class="search-q"><label class="visually-hidden" for="search-input"><?= \App\Lang::t('search.query') ?></label><input type="search" id="search-input" name="q" aria-describedby="search-hint" maxlength="200" value="<?= $this->e($q) ?>" placeholder="<?= $this->e(\App\Lang::t('search.placeholder')) ?>"><a class="search-clear" href="<?= $this->e(\App\Search\ActiveFilters::withoutQuery($filters, $sort)) ?>" aria-label="<?= $this->e(\App\Lang::t('search.clear_query')) ?>" data-search-clear><?= "×" ?></a></div>
      <button type="submit" class="btn-primary"><?= \App\Lang::t('search.submit') ?></button>
    </div>
    <p id="search-hint" class="search-hint"><span class="hint-start"><?= \App\Lang::t('search.hint') ?></span><span class="hint-asked"><?= \App\Lang::t('search.lede') ?></span></p>
    <ul id="search-suggestions" data-search-suggestions hidden></ul>
    <p id="search-status" data-search-status class="search-status" role="status" aria-live="polite" aria-atomic="true"></p>
    <?php $asked = $q !== '' || $result['mode'] !== 'none'; ?>
    <div class="rail-bar">
      <span class="rail-total" data-rail-total data-one="<?= $this->e(\App\Lang::t('search.stories_one')) ?>" data-many="<?= $this->e(\App\Lang::t('search.stories_n', ['count' => '{count}'])) ?>"<?= $asked ? '' : ' hidden' ?>><?= $asked ? $this->e(\App\Lang::t((int) $result['total'] === 1 ? 'search.stories_one' : 'search.stories_n', ['count' => (int) $result['total']])) : '' ?></span>
      <a class="rail-open" href="#search-rail"><?= $chips !== [] ? $this->e(\App\Lang::t('search.filters_n', ['count' => count($chips)])) : \App\Lang::t('search.filters') ?></a>
    </div>
    <section class="search-recent" data-recent hidden data-remove="<?= $this->e(\App\Lang::t('search.recent_remove', ['q' => '{q}'])) ?>" data-glyph-x="<?= $this->e("×") ?>">
      <h2 class="section-label"><?= \App\Lang::t('search.recent_heading') ?></h2>
      <ul class="chip-list" data-recent-list></ul>
    </section>
    <aside id="search-rail" class="search-rail" aria-label="<?= $this->e(\App\Lang::t('search.filters')) ?>">
      <div class="rail-head">
        <h2><?= \App\Lang::t('search.filters') ?></h2>
        <span class="rail-actions"><a class="rail-clear" href="/search<?= $q === '' ? '' : '?q=' . $this->e(rawurlencode($q)) ?>"><?= \App\Lang::t('search.clear_all') ?></a><a class="rail-close" href="#search-status"><?= \App\Lang::t('search.close_filters') ?></a></span>
      </div>
      <?php $statusCounts = $result['counts']['status']; $anyCount = (int) ($statusCounts[0] ?? 0) + (int) ($statusCounts[1] ?? 0);
            $completed = $filters['completed'] ?? null; ?>
      <fieldset class="rail-group rail-status">
        <legend><?= \App\Lang::t('browse.status') ?></legend>
        <div class="seg">
          <label class="seg-opt"><input type="radio" name="completed" value=""<?= $completed === null ? ' checked' : '' ?>>
            <span class="seg-label"><?= \App\Lang::t('browse.status_any') ?></span>
            <span class="count" data-search-count="status:any"><?= $anyCount ?></span></label>
          <?php foreach ([1 => 'browse.status_complete', 0 => 'browse.status_wip'] as $value => $label): ?>
          <label class="seg-opt"><input type="radio" name="completed" value="<?= $value ?>"<?= $completed === (bool) $value ? ' checked' : '' ?>>
            <span class="seg-label"><?= \App\Lang::t($label) ?></span>
            <span class="count" data-search-count="status:<?= $value ?>"><?= (int) ($statusCounts[$value] ?? 0) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="rail-group">
        <legend><?= \App\Lang::t('search.rating') ?></legend>
        <label class="rail-row"><input type="radio" name="rating" value=""<?= (int) ($filters['rating_id'] ?? 0) === 0 ? ' checked' : '' ?>>
          <span class="row-label"><?= \App\Lang::t('browse.status_any') ?></span></label>
        <?php foreach ($ratings as $r): ?>
        <label class="rail-row"><input type="radio" name="rating" value="<?= (int) $r['id'] ?>"<?= (int) ($filters['rating_id'] ?? 0) === (int) $r['id'] ? ' checked' : '' ?>>
          <span class="row-label"><?= $this->e($r['label']) ?></span>
          <span class="count" data-search-count="rating:<?= (int) $r['id'] ?>"><?= (int) $r['count'] ?></span></label>
        <?php endforeach; ?>
      </fieldset>
      <?php $picked = $filters['category'] ?? [];
            $shown = array_slice($categories, 0, 5); $folded = array_slice($categories, 5);
            $foldOpen = array_filter($folded, static fn (array $c): bool => in_array($c['slug'], $picked, true)) !== [];
            $row = function (array $c) use ($picked): string { ob_start(); ?>
        <label class="rail-row"><input type="checkbox" name="category[]" value="<?= $this->e($c['slug']) ?>"<?= in_array($c['slug'], $picked, true) ? ' checked' : '' ?>>
          <span class="row-label"><?= $this->e($c['name']) ?></span>
          <span class="count" data-search-count="category:<?= $this->e($c['slug']) ?>"><?= (int) $c['count'] ?></span></label>
        <?php return (string) ob_get_clean(); }; ?>
      <fieldset class="rail-group">
        <legend><?= \App\Lang::t('search.category') ?></legend>
        <?php foreach ($shown as $c) echo $row($c); ?>
        <?php if ($folded !== []): ?>
        <details class="rail-more-rows"<?= $foldOpen ? ' open' : '' ?>>
          <summary><span class="more-closed"><?= \App\Lang::t('search.show_more', ['count' => count($folded)]) ?></span><span class="more-open"><?= \App\Lang::t('search.show_fewer') ?></span></summary>
          <?php foreach ($folded as $c) echo $row($c); ?>
        </details>
        <?php endif; ?>
        <p class="visually-hidden"><?= \App\Lang::t('search.category_counts_help') ?></p>
      </fieldset>
      <div class="rail-group">
        <label class="rail-label" for="rail-language"><?= \App\Lang::t('browse.language') ?></label>
        <select id="rail-language" name="language">
          <option value=""><?= \App\Lang::t('browse.any_language') ?></option>
          <?php $languages = $result['languages']; $language = (string) ($filters['language'] ?? '');
              if ($language !== '' && !in_array($language, $languages, true)) $languages[] = $language; ?>
          <?php foreach ($languages as $l): ?><option value="<?= $this->e($l) ?>" data-search-count="language:<?= $this->e($l) ?>" data-count-label="<?= $this->e($l) ?>"<?= $language === $l ? ' selected' : '' ?>><?= $this->e($l) ?> (<?= $this->e((string) (int) ($result['counts']['language'][$l] ?? 0)) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <?php if (($sort ?? 'relevance') === 'updated'): ?><input type="hidden" name="sort" value="updated"><?php endif; ?>
      <?php if (($filters['character'] ?? null) !== null): ?>
      <input type="hidden" name="character" value="<?= (int) $filters['character'] ?>"><p class="hint"><?= \App\Lang::t('search.filtered_character') ?> <a href="/search?<?= $this->e(http_build_query(array_filter(['q' => $q, 'tag' => $filters['tag'] ?? '']))) ?>"><?= \App\Lang::t('search.clear_filter') ?></a></p>
      <?php endif; ?>
      <div class="rail-group rail-tags" data-js-module="tagchips">
        <h3 class="rail-label"><?= \App\Lang::t('search.tags') ?></h3>
        <p class="hint" hidden data-tag-hint><?= \App\Lang::t('search.tags_click_hint') ?></p>
<?= $this->render('browse/_tag_picker', ['result' => $result, 'tagFilter' => $filters['tag_filter'], 'rail' => true]) ?>
        <details class="rail-by-name"<?= ($filters['tags'] ?? '') !== '' || ($filters['xtags'] ?? '') !== '' ? ' open' : '' ?>>
          <summary><?= \App\Lang::t('search.tag_names') ?></summary>
          <label><?= \App\Lang::t('search.tags') ?> <input name="tags" maxlength="520" value="<?= $this->e((string) ($filters['tags'] ?? '')) ?>"></label>
          <label><?= \App\Lang::t('search.xtags') ?> <input name="xtags" maxlength="520" value="<?= $this->e((string) ($filters['xtags'] ?? '')) ?>"></label>
          <p class="hint"><?= \App\Lang::t('search.tags_hint') ?></p>
        </details>
        <p class="hint"><a href="/browse/filter<?php
            $browseQ = http_build_query(array_filter([
                'tag' => $filters['tag'] ?? null,
                'xtag' => $filters['xtag'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []));
            echo $browseQ === '' ? '' : '?' . $this->e($browseQ); ?>"><?= \App\Lang::t('search.try_browse') ?></a></p>
      </div>
      <button type="submit" class="btn-primary rail-apply" data-show-one="<?= $this->e(\App\Lang::t('search.show_story')) ?>" data-show-many="<?= $this->e(\App\Lang::t('search.show_stories', ['count' => '{count}'])) ?>"><?= \App\Lang::t('search.apply') ?></button>
    </aside>
  </form>
<div id="search-results" data-search-results><?= $this->render('search/_results', compact('q', 'filters', 'result', 'sort', 'page', 'perPage', 'chips')) ?></div>
</div>
