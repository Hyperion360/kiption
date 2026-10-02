<?php // app/views/story/read.php ?>
<?php // C8 (frames M2/T1/D1 + focus D2): sticky reader header with the
     // story-span progressbar, bottom control bar, :target sheets for
     // contents/bookmarks and text settings, and the end-of-chapter block.
     // The h-entry microformats block and the read beacon keep their exact
     // bytes (MicroformatsTest pins them). Every feature works with
     // scripting disabled: links, forms, native inputs (radios, the size
     // range), and :target; the data-js-module markers only ever summon the
     // deferred enhancement layer (keys, position, prefs, infinite).
     $this->layout('layout');
     $p = \App\Features\Reader\Prefs::current($request ?? null);
     $member = (int) ($me ?? 0) !== 0;
     $focus = ($focus ?? false) === true;
     $bookmarked = false;
     $titles = [];
     foreach ($chapters as $c) { $titles[(int) $c['position']] = (string) $c['title']; }
     foreach ($bookmarks as $b) {
         if ($b['position'] !== null && (int) $b['position'] === (int) $position) { $bookmarked = true; break; }
     }
     $barStyle = '--p-start:' . (int) $pct_start . '%;--p-end:' . (int) $pct_end . '%';
     $roman = \App\Features\Reader\Roman::numeral((int) $position);
     $rawTitle = (string) ($chapter['title'] ?? '');
     // The keys module's wiring (AppJsContractTest): every URL the keyboard
     // shortcuts navigate to is resolved here, never invented client-side.
     // The attributes render always - focus mode or not - and carry story
     // data only, so the cached guest bytes stay stable. data-next-url is
     // the infinite module's initial fetch target (the next chapter's
     // FRAGMENT endpoint; data-next stays the keys module's read URL).
     $baseUrl = '/story/read/' . $story['slug'] . '/' . (int) $position; ?>
<div class="reader<?= $focus ? ' reader-focus' : '' ?>" data-js-module="keys"
     data-prev="<?= $this->e($prev !== null ? $baseUrl . '/' . (int) $prev : '') ?>"
     data-next="<?= $this->e($next !== null ? $baseUrl . '/' . (int) $next : '') ?>"
     data-focus-url="<?= $this->e($baseUrl . '?focus=1') ?>"
     data-exit-focus="<?= $this->e($baseUrl) ?>"
     data-text-url="<?= $this->e($baseUrl . '#text') ?>"
     data-next-url="<?= $this->e($next !== null ? '/story/fragment/' . $story['slug'] . '/' . (int) $next : '') ?>">
  <?php /* D2 focus: the comp's breadcrumb bar (brand · story · chapter, chapter-of right).
         Regular D1 desktop: the top bar gains brand + byline + the three dark
         controls (CSS shows them only at 1024+). */ ?>
  <header class="reader-head<?= $focus ? ' reader-breadcrumb' : '' ?>">
    <?php if ($focus): ?>
    <span class="rt-left"><a class="rt-brand" href="/"><?= \App\Lang::t('nav.brand') ?></a><span class="rt-sep" aria-hidden="true">·</span>
    <span class="rt-story"><?= $this->e($story['title']) ?></span><span class="rt-sep" aria-hidden="true">·</span>
    <span class="rt-chapter"><?= $this->e($rawTitle !== '' ? $roman . ' · ' . $rawTitle : \App\Lang::t('story.chapter_n', ['n' => $roman])) ?></span></span>
    <span class="reader-pct" data-js="reader-pct"><?= \App\Lang::t('story.chapter_of_pct', ['n' => (int) $position, 'm' => (int) $total, 'pct' => (int) $pct_end]) ?></span>
    <?php else: ?>
    <a class="rt-brand" href="/"><?= \App\Lang::t('nav.brand') ?></a>
    <a class="reader-back" href="/story/view/<?= $this->e($story['slug']) ?>" aria-label="<?= $this->e(\App\Lang::t('reader.back_to_story')) ?>">←</a>
    <div class="reader-titles"><span class="rt-story"><?= $this->e($story['title']) ?></span>
      <span class="rt-chapter"><?= $this->e($rawTitle !== '' ? $roman . ' · ' . $rawTitle : \App\Lang::t('story.chapter_n', ['n' => $roman])) ?></span></div>
    <span class="rt-by"><?= \App\Lang::t('story.by') ?> <?= $this->e($story['penname']) ?></span>
    <span class="reader-pct" data-js="reader-pct"><?= (int) $pct_end ?>%</span>
    <nav class="rt-ctls" aria-label="<?= $this->e(\App\Lang::t('reader.bar_aria')) ?>">
      <a class="rt-ctl" href="#contents"><?= \App\Lang::t('reader.contents') ?></a>
      <a class="rt-ctl" href="#text"><?= \App\Lang::t('reader.text') ?></a>
      <?php if ($member): ?>
        <?php if ($bookmarked): ?>
        <form method="post" action="/reader/bookmarkremove/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline"><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="rt-ctl is-saved"><?= \App\Lang::t('reader.bookmark_saved') ?></button></form>
        <?php else: ?>
        <form method="post" action="/reader/bookmarkadd/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline"><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="rt-ctl"><?= \App\Lang::t('reader.bookmark') ?></button></form>
        <?php endif; ?>
      <?php else: ?><a class="rt-ctl" href="/auth/login"><?= \App\Lang::t('reader.bookmark') ?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php /* the position module's wiring (AppJsContractTest): the chapter's own
       span of the story rides the data attributes, so the module only ever
       maps scroll into this server-stamped band; story data only, so the
       cached guest bytes stay stable. */ ?>
    <div class="reader-progress" role="progressbar" aria-label="<?= $this->e(\App\Lang::t('reader.progress_aria')) ?>"
         aria-valuenow="<?= (int) $pct_end ?>" aria-valuemin="0" aria-valuemax="100"
         style="<?= $this->e($barStyle) ?>"
         data-js="reader-progress" data-js-module="position"
         data-p-start="<?= (int) $pct_start ?>" data-p-end="<?= (int) $pct_end ?>"></div>
  </header>
  <div class="reader-main" data-js-module="infinite">
    <?php /* the chapter unit (article + chapter-end) is the shared partial:
       the infinite module appends further units before its sentinel; the
       next-chapter link inside and the nav below stay the noscript paths */ ?>
    <?= $this->render('story/_chapter', [
        'story' => $story,
        'chapter' => $chapter,
        'position' => $position,
        'total' => $total,
        'next' => $next,
        'titles' => $titles,
        'csrf' => $csrf,
        'pct_start' => $pct_start,
        'pct_end' => $pct_end,
    ]) ?>
    <nav class="chapter-nav" aria-label="<?= $this->e(\App\Lang::t('story.chapter_nav_aria')) ?>">
      <?php if ($prev !== null): ?>
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $prev ?>"><?= \App\Lang::t('common.previous') ?></a>
      <?php else: ?><span></span><?php endif; ?>
      <?php if ($next !== null): ?>
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= $next ?>"><?= \App\Lang::t('common.next') ?></a>
      <?php endif; ?>
    </nav>
  </div>
  <nav class="reader-bar" aria-label="<?= $this->e(\App\Lang::t('reader.bar_aria')) ?>">
    <?php /* icon-over-caption items: a 20px glyph slot above a 12px caption; prev/next ride the chapter-nav block above the bar */ ?>
    <a href="#contents" aria-label="<?= $this->e(\App\Lang::t('reader.contents')) ?>">
      <span class="bar-top"><svg class="bar-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/></svg></span>
      <span class="bar-caption"><?= \App\Lang::t('reader.contents') ?></span>
    </a>
    <span class="bar-chapter">
      <span class="bar-top"><span class="bar-count"><?= (int) $position ?> / <?= (int) $total ?></span></span>
      <span class="bar-caption"><?= \App\Lang::t('reader.chapter') ?></span>
    </span>
    <a href="#text" aria-label="<?= $this->e(\App\Lang::t('reader.text')) ?>">
      <span class="bar-top"><span class="bar-glyph" aria-hidden="true">Aa</span></span>
      <span class="bar-caption"><?= \App\Lang::t('reader.text') ?></span>
    </a>
    <?php if ($member): ?>
      <?php if ($bookmarked): ?>
      <form method="post" action="/reader/bookmarkremove/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline bar-bookmark">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit" class="is-saved" aria-label="<?= $this->e(\App\Lang::t('reader.bookmark_saved')) ?>">
          <span class="bar-top"><svg class="bar-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M7 4h10v16l-5-3.5L7 20Z"/></svg></span>
          <span class="bar-caption"><?= \App\Lang::t('reader.bookmark_saved') ?></span>
        </button>
      </form>
      <?php else: ?>
      <form method="post" action="/reader/bookmarkadd/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline bar-bookmark">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit" aria-label="<?= $this->e(\App\Lang::t('reader.bookmark')) ?>">
          <span class="bar-top"><svg class="bar-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M7 4h10v16l-5-3.5L7 20Z"/></svg></span>
          <span class="bar-caption"><?= \App\Lang::t('reader.bookmark') ?></span>
        </button>
      </form>
      <?php endif; ?>
    <?php else: ?>
    <a href="/auth/login" aria-label="<?= $this->e(\App\Lang::t('reader.bookmark')) ?>">
      <span class="bar-top"><svg class="bar-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M7 4h10v16l-5-3.5L7 20Z"/></svg></span>
      <span class="bar-caption"><?= \App\Lang::t('reader.bookmark') ?></span>
    </a>
    <?php endif; ?>
  </nav>
  <div id="contents" class="sheet" role="dialog" aria-label="<?= $this->e(\App\Lang::t($member ? 'reader.contents_bookmarks' : 'reader.contents')) ?>">
    <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a><a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
    <?php /* radio tabs: two native radios + :checked CSS switch the panes (zero JS) */ ?>
    <?php if ($member): ?>
    <input type="radio" name="ctab" id="ctab-contents" class="ctab-radio" checked>
    <input type="radio" name="ctab" id="ctab-bookmarks" class="ctab-radio">
    <div class="sheet-tabs">
      <label for="ctab-contents"><?= \App\Lang::t('reader.contents') ?></label>
      <label for="ctab-bookmarks"><?= \App\Lang::t('reader.bookmarks') ?><?= $bookmarks !== [] ? ' · ' . count($bookmarks) : '' ?></label>
    </div>
    <?php endif; ?>
    <div class="pane pane-contents">
      <h2 class="sheet-title"><?= \App\Lang::t('reader.contents') ?></h2>
      <?php /* member annotations (D1): the current chapter carries its minutes-left,
             chapters with bookmarks carry their count; word counts stay for mobile */ ?>
      <ol class="chapter-list">
        <?php
        $bmCount = [];
        foreach ($bookmarks as $b) { if ($b['position'] !== null) { $p2 = (int) $b['position']; $bmCount[$p2] = ($bmCount[$p2] ?? 0) + 1; } }
        foreach ($chapters as $c): $cur = (int) $c['position'] === (int) $position; ?>
        <li<?= $cur ? ' class="is-current"' : '' ?>>
          <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>"<?= $cur ? ' aria-current="page"' : '' ?>>
            <span class="ch-num"><?= \App\Features\Reader\Roman::numeral((int) $c['position']) ?></span>
            <span class="ch-title"><?= $this->e($c['title']) ?></span>
            <span class="ch-note"><?php if ($cur && ($progress['minutes_left'] ?? null) !== null): ?><?= \App\Lang::t('reader.min_left', ['n' => (int) $progress['minutes_left']]) ?><?php elseif (($bmCount[(int) $c['position']] ?? 0) > 0): $n2 = $bmCount[(int) $c['position']]; ?><?= $n2 ?> <?= \App\Lang::t($n2 === 1 ? 'reader.bookmark_one' : 'reader.bookmark_many') ?><?php endif; ?></span>
            <span class="ch-meta"><?= number_format((int) $c['word_count']) ?></span>
          </a></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php if ($member): ?>
    <div class="pane pane-bookmarks">
      <h2 class="sheet-title"><?= \App\Lang::t('reader.bookmarks') ?></h2>
      <?php if ($bookmarks === []): ?><p class="meta"><?= \App\Lang::t('common.none_yet') ?></p>
      <?php else: ?>
      <ul class="bookmark-list">
        <?php foreach ($bookmarks as $b): ?>
        <li>
          <?php if ($b['position'] !== null): ?>
          <a class="bm-chapter" href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $b['position'] ?>"><?= \App\Features\Reader\Roman::numeral((int) $b['position']) ?> · <?= $this->e(($titles[(int) $b['position']] ?? '') !== '' ? $titles[(int) $b['position']] : \App\Lang::t('story.chapter_n', ['n' => (int) $b['position']])) ?></a>
          <?php endif; ?>
          <?php if ($b['note'] !== ''): ?><p class="bm-note"><?= $this->e($b['note']) ?></p><?php endif; ?>
          <?php if ($b['position'] !== null): ?>
          <form method="post" action="/reader/bookmarkremove/<?= $this->e($story['slug']) ?>/<?= (int) $b['position'] ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
          </form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div id="text" class="sheet" role="dialog" aria-label="<?= $this->e(\App\Lang::t('reader.text_aria')) ?>">
    <a class="sheet-handle" href="#sheet-close" aria-hidden="true" tabindex="-1"></a><a class="sheet-done" href="#sheet-close"><?= \App\Lang::t('common.done') ?></a>
    <form method="post" action="/reader/settings" class="text-settings" data-js="settings-form" data-js-module="prefs">
      <div class="panel-head"><h2 class="sheet-title"><?= \App\Lang::t('reader.text') ?></h2><button type="submit" name="reset" value="1" class="reset-link"><?= \App\Lang::t('common.reset') ?></button></div>
      <input type="hidden" name="_token" value="<?= $this->e($csrf ?? '') ?>">
      <input type="hidden" name="return_to" value="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <?php /* theme radio default: cookie (the effective render), else the member's STORED row, else auto. Without the row fallback, a member whose cookie expired would see auto checked, and a typography-only save would rewrite the stored theme to auto's row value (paper). */ ?>
      <?php $t = $theme ?? ($prefsTheme ?? null); $t = $t === null ? 'auto' : $t; ?>
      <fieldset><legend><?= \App\Lang::t('reader.size') ?> <span class="size-readout"><?= \App\Lang::t('reader.px', ['n' => (int) $p->size]) ?></span></legend>
        <div class="size-row">
          <span class="size-end"><span class="size-a" aria-hidden="true">A</span> <?= \App\Lang::t('reader.size_small') ?></span>
          <input type="range" name="size" min="16" max="24" step="1" value="<?= (int) $p->size ?>" aria-label="<?= $this->e(\App\Lang::t('reader.size')) ?>" data-js-pref-target="size">
          <span class="size-end"><span class="size-a size-a-lg" aria-hidden="true">A</span> <?= \App\Lang::t('reader.size_large') ?></span>
        </div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.typeface') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::TYPEFACES as $f): ?>
          <label><input type="radio" name="typeface" value="<?= $f ?>"<?= $f === $p->typeface ? ' checked' : '' ?> data-js-pref="typeface"><span><?= \App\Lang::t('reader.typeface_' . $f) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.spacing') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::SPACINGS as $s): ?>
          <label><input type="radio" name="spacing" value="<?= $s ?>"<?= $s === $p->spacing ? ' checked' : '' ?> data-js-pref="spacing"><span><?= \App\Lang::t('reader.spacing_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.paragraphs') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::PARAGRAPHS as $s): ?>
          <label><input type="radio" name="paragraphs" value="<?= $s ?>"<?= $s === $p->paragraphs ? ' checked' : '' ?> data-js-pref="paragraphs"><span><?= \App\Lang::t('reader.paragraphs_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.theme') ?></legend>
        <div class="segmented swatches"><?php foreach (\App\Theme::VALUES as $v): ?>
          <label class="swatch swatch-<?= $v ?>"><input type="radio" name="theme" value="<?= $v ?>"<?= $v === $t ? ' checked' : '' ?> data-js-pref="theme"><span class="swatch-aa">Aa</span><span><?= \App\Lang::t('theme.' . $v) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.width') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::WIDTHS as $s): ?>
          <label><input type="radio" name="width" value="<?= $s ?>"<?= $s === $p->width ? ' checked' : '' ?> data-js-pref="width"><span><?= \App\Lang::t('reader.width_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.mode') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::MODES as $s): ?>
          <label><input type="radio" name="mode" value="<?= $s ?>"<?= $s === $p->mode ? ' checked' : '' ?> data-js-pref="mode"><span><?= \App\Lang::t('reader.mode_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <button type="submit"><?= \App\Lang::t('common.save') ?></button>
    </form>
  </div>
  <?php if ($focus): ?>
  <div class="focus-hint" aria-hidden="false"><span><?= \App\Lang::t('reader.focus_mode') ?></span> · <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>"><?= \App\Lang::t('reader.exit_focus') ?></a></div>
  <nav class="reader-dock" aria-label="<?= $this->e(\App\Lang::t('reader.focus_aria')) ?>">
    <a href="#contents" aria-label="<?= $this->e(\App\Lang::t('reader.contents')) ?>">≡</a>
    <a href="#text" aria-label="<?= $this->e(\App\Lang::t('reader.text')) ?>">Aa</a>
    <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" aria-label="<?= $this->e(\App\Lang::t('reader.exit_focus')) ?>">×</a>
  </nav>
  <?php endif; ?>
  <?php /* The keyboard hint bar (keys module): always-present markup so the
     bytes never depend on scripting, hidden until the enhancement layer
     runs (html.js reveals it; noscript never sees it). Each string's first
     word is the key cap, the rest the label; the strings ride reader.keys_*. */ ?>
  <div class="hint-keys" hidden>
    <?php foreach (['reader.keys_next', 'reader.keys_prev', 'reader.keys_focus', 'reader.keys_text', 'reader.keys_close'] as $keysHint):
        $cap = explode(' ', (string) \App\Lang::t($keysHint), 2); ?>
    <span class="hint-item"><kbd><?= $this->e($cap[0]) ?></kbd><?= isset($cap[1]) && $cap[1] !== '' ? ' ' . $this->e($cap[1]) : '' ?></span>
    <?php endforeach; ?>
  </div>
</div>
