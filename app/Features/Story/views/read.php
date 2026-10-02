<?php // app/views/story/read.php ?>
<?php // C8 (frames M2/T1/D1 + focus D2): sticky reader header with the
     // story-span progressbar, bottom control bar, :target sheets for
     // contents/bookmarks and text settings, and the end-of-chapter block.
     // The h-entry microformats block and the read beacon keep their exact
     // bytes (MicroformatsTest pins them). Links, forms, native inputs
     // (radios, the size range), and :target only: zero JavaScript.
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
     $chapterTitle = $rawTitle !== '' ? $rawTitle : \App\Lang::t('story.chapter_n', ['n' => (int) $position]);
     $endLabel = $rawTitle !== ''
         ? \App\Lang::t('reader.end_of_chapter_titled', ['n' => $roman, 'title' => $rawTitle])
         : \App\Lang::t('reader.end_of_chapter', ['n' => $roman]); ?>
<div class="reader<?= $focus ? ' reader-focus' : '' ?>">
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
  <div class="reader-main">
    <article class="h-entry">
      <?php /* the byline/dates breadcrumb: the comp drops it visually; the row stays for the mf2 time elements MicroformatsTest pins */ ?>
      <header class="chapter-meta visually-hidden">
        <a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a>
        <?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
        | <?= \App\Lang::t('story.chapter_of', ['n' => $position, 'm' => $total]) ?>
        | <?= \App\Lang::t('story.published') ?> <time class="dt-published" datetime="<?= $this->e($story['created_at']) ?>"><?= $this->e(substr((string) $story['created_at'], 0, 10)) ?></time>
        | <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time>
      </header>
      <div class="prose e-content">
        <div class="ch-kicker"><?= $this->e(\App\Lang::t('story.chapter_n', ['n' => $roman])) ?></div>
        <h1 class="p-name"><?= $this->e($chapterTitle) ?></h1>
        <?php if (($chapter['notes_before'] ?? '') !== ''): ?>
          <div class="chapter-meta"><?= \App\Markdown::render($chapter['notes_before']) ?></div>
        <?php endif; ?>
        <?= \App\Markdown::render($chapter['content']) /* markdown at rest; raw HTML cannot be stored */ ?>
        <?php if (($chapter['notes_after'] ?? '') !== ''): ?>
          <div class="chapter-meta"><?= \App\Markdown::render($chapter['notes_after']) ?></div>
        <?php endif; ?>
      </div>
      <?php /* the read beacon: a zero-JS read counter into page_stats; the src carries the story id and the chapter's id (never the position) */ ?>
      <img src="/beacon/read/<?= (int) $story['id'] ?>/<?= (int) $story['ch_id'] ?>" alt="" width="1" height="1" loading="lazy">
    </article>
    <footer class="chapter-end" role="separator" aria-label="<?= $this->e($endLabel) ?>">
      <span class="dots" aria-hidden="true"></span>
      <p><?= $this->e($endLabel) ?></p>
      <div class="chapter-end-actions">
        <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
          <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
          <button type="submit"><?= \App\Lang::t('story.leave_kudos') ?></button>
        </form>
        <a href="/story/view/<?= $this->e($story['slug']) ?>#reviews"><?= $this->e(\App\Lang::t('reader.review_link')) ?> · <?= (int) ($story['review_count'] ?? 0) ?></a>
      </div>
      <?php if ($next !== null):
          $nextTitle = ($titles[(int) $next] ?? '') !== '' ? $titles[(int) $next] : \App\Lang::t('story.chapter_n', ['n' => (int) $next]); ?>
      <div class="next-chapter">
        <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $next ?>">
          <span class="ch-kicker"><?= $this->e(\App\Lang::t('reader.continues', ['roman' => \App\Features\Reader\Roman::numeral((int) $next)])) ?></span>
          <span class="next-title"><?= $this->e($nextTitle) ?></span>
        </a>
      </div>
      <?php endif; ?>
    </footer>
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
    <form method="post" action="/reader/settings" class="text-settings">
      <div class="panel-head"><h2 class="sheet-title"><?= \App\Lang::t('reader.text') ?></h2><button type="submit" name="reset" value="1" class="reset-link"><?= \App\Lang::t('common.reset') ?></button></div>
      <input type="hidden" name="_token" value="<?= $this->e($csrf ?? '') ?>">
      <input type="hidden" name="return_to" value="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <?php /* theme radio default: cookie (the effective render), else the member's STORED row, else auto. Without the row fallback, a member whose cookie expired would see auto checked, and a typography-only save would rewrite the stored theme to auto's row value (paper). */ ?>
      <?php $t = $theme ?? ($prefsTheme ?? null); $t = $t === null ? 'auto' : $t; ?>
      <fieldset><legend><?= \App\Lang::t('reader.size') ?> <span class="size-readout"><?= \App\Lang::t('reader.px', ['n' => (int) $p->size]) ?></span></legend>
        <div class="size-row">
          <span class="size-end"><span class="size-a" aria-hidden="true">A</span> <?= \App\Lang::t('reader.size_small') ?></span>
          <input type="range" name="size" min="16" max="24" step="1" value="<?= (int) $p->size ?>" aria-label="<?= $this->e(\App\Lang::t('reader.size')) ?>">
          <span class="size-end"><span class="size-a size-a-lg" aria-hidden="true">A</span> <?= \App\Lang::t('reader.size_large') ?></span>
        </div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.typeface') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::TYPEFACES as $f): ?>
          <label><input type="radio" name="typeface" value="<?= $f ?>"<?= $f === $p->typeface ? ' checked' : '' ?>><span><?= \App\Lang::t('reader.typeface_' . $f) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.spacing') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::SPACINGS as $s): ?>
          <label><input type="radio" name="spacing" value="<?= $s ?>"<?= $s === $p->spacing ? ' checked' : '' ?>><span><?= \App\Lang::t('reader.spacing_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.paragraphs') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::PARAGRAPHS as $s): ?>
          <label><input type="radio" name="paragraphs" value="<?= $s ?>"<?= $s === $p->paragraphs ? ' checked' : '' ?>><span><?= \App\Lang::t('reader.paragraphs_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.theme') ?></legend>
        <div class="segmented swatches"><?php foreach (\App\Theme::VALUES as $v): ?>
          <label class="swatch swatch-<?= $v ?>"><input type="radio" name="theme" value="<?= $v ?>"<?= $v === $t ? ' checked' : '' ?>><span class="swatch-aa">Aa</span><span><?= \App\Lang::t('theme.' . $v) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.width') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::WIDTHS as $s): ?>
          <label><input type="radio" name="width" value="<?= $s ?>"<?= $s === $p->width ? ' checked' : '' ?>><span><?= \App\Lang::t('reader.width_' . $s) ?></span></label>
        <?php endforeach; ?></div></fieldset>
      <fieldset><legend><?= \App\Lang::t('reader.mode') ?></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::MODES as $s): ?>
          <label><input type="radio" name="mode" value="<?= $s ?>"<?= $s === $p->mode ? ' checked' : '' ?>><span><?= \App\Lang::t('reader.mode_' . $s) ?></span></label>
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
</div>
