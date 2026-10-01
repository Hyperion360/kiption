<?php // app/views/story/read.php ?>
<?php // C8 (frames M2/T1/D1 + focus D2): sticky reader header with the
     // story-span progressbar, bottom control bar, :target sheets for
     // contents/bookmarks and text settings, and the end-of-chapter block.
     // The h-entry microformats block and the read beacon keep their exact
     // bytes (MicroformatsTest pins them). Links, forms, native radios, and
     // :target only: zero JavaScript.
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
     $barStyle = '--p-start:' . (int) $pct_start . '%;--p-end:' . (int) $pct_end . '%'; ?>
<div class="reader<?= $focus ? ' reader-focus' : '' ?>">
  <header class="reader-head">
    <a class="reader-back" href="/story/view/<?= $this->e($story['slug']) ?>" aria-label="<?= $this->e(\App\Lang::t('reader.back_to_story')) ?>">←</a>
    <div class="reader-titles"><span class="rt-story"><?= $this->e($story['title']) ?></span>
      <span class="rt-chapter"><?= \App\Lang::t('story.chapter_of', ['n' => (int) $position, 'm' => (int) $total]) ?></span></div>
    <span class="reader-pct"><?= (int) $pct_end ?>%</span>
    <div class="reader-progress" role="progressbar" aria-label="<?= $this->e(\App\Lang::t('reader.progress_aria')) ?>"
         aria-valuenow="<?= (int) $pct_end ?>" aria-valuemin="0" aria-valuemax="100"
         style="<?= $this->e($barStyle) ?>"></div>
  </header>
  <div class="reader-main">
    <article class="h-entry">
      <header class="chapter-meta">
        <a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a>
        <?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
        | <?= \App\Lang::t('story.chapter_of', ['n' => $position, 'm' => $total]) ?>
        | <?= \App\Lang::t('story.published') ?> <time class="dt-published" datetime="<?= $this->e($story['created_at']) ?>"><?= $this->e(substr((string) $story['created_at'], 0, 10)) ?></time>
        | <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time>
      </header>
      <div class="prose e-content">
        <h1 class="p-name"><?= $this->e($chapter['title'] !== '' ? $chapter['title'] : \App\Lang::t('story.chapter_n', ['n' => $position])) ?></h1>
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
    <footer class="chapter-end" role="separator" aria-label="<?= $this->e(\App\Lang::t('reader.end_of_chapter', ['n' => \App\Features\Reader\Roman::numeral((int) $position)])) ?>">
      <span class="dots" aria-hidden="true"></span>
      <p><?= \App\Lang::t('reader.end_of_chapter', ['n' => \App\Features\Reader\Roman::numeral((int) $position)]) ?></p>
      <div class="chapter-end-actions">
        <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
          <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
          <button type="submit"><?= \App\Lang::t('story.leave_kudos') ?></button>
        </form>
        <a href="/story/view/<?= $this->e($story['slug']) ?>#reviews"><?= \App\Lang::t('reader.review_link') ?></a>
      </div>
      <?php if ($next !== null): ?><a class="next-chapter" href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $next ?>"><?= \App\Lang::t('reader.next_chapter') ?></a><?php endif; ?>
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
    <a href="#contents"><?= \App\Lang::t('reader.contents') ?></a>
    <span class="bar-chapter"><?php if ($prev !== null): ?><a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $prev ?>" aria-label="<?= $this->e(\App\Lang::t('common.previous')) ?>">‹</a><?php endif; ?>
      <span><?= (int) $position ?> / <?= (int) $total ?></span>
      <?php if ($next !== null): ?><a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $next ?>" aria-label="<?= $this->e(\App\Lang::t('common.next')) ?>">›</a><?php endif; ?></span>
    <a href="#text"><?= \App\Lang::t('reader.text') ?></a>
    <?php if ($member): ?>
      <?php if ($bookmarked): ?>
      <form method="post" action="/reader/bookmarkremove/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline bar-bookmark">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit" class="is-saved"><?= \App\Lang::t('reader.bookmark_saved') ?></button>
      </form>
      <?php else: ?>
      <form method="post" action="/reader/bookmarkadd/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" class="inline bar-bookmark">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit"><?= \App\Lang::t('reader.bookmark') ?></button>
      </form>
      <?php endif; ?>
    <?php else: ?><a href="/auth/login"><?= \App\Lang::t('reader.bookmark') ?></a><?php endif; ?>
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
      <ol class="chapter-list">
        <?php foreach ($chapters as $c): $cur = (int) $c['position'] === (int) $position; ?>
        <li<?= $cur ? ' class="is-current"' : '' ?>>
          <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $c['position'] ?>"<?= $cur ? ' aria-current="page"' : '' ?>>
            <span class="ch-num"><?= \App\Features\Reader\Roman::numeral((int) $c['position']) ?></span>
            <span class="ch-title"><?= $this->e($c['title']) ?></span>
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
      <input type="hidden" name="_token" value="<?= $this->e($csrf ?? '') ?>">
      <input type="hidden" name="return_to" value="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>">
      <?php /* theme radio default: cookie (the effective render), else the member's STORED row, else auto. Without the row fallback, a member whose cookie expired would see auto checked, and a typography-only save would rewrite the stored theme to auto's row value (paper). */ ?>
      <?php $t = $theme ?? ($prefsTheme ?? null); $t = $t === null ? 'auto' : $t; ?>
      <fieldset><legend><?= \App\Lang::t('reader.size') ?> <span class="val"><?= \App\Lang::t('reader.px', ['n' => (int) $p->size]) ?></span></legend>
        <div class="segmented"><?php foreach (\App\Features\Reader\Prefs::SIZES as $s): ?>
          <label><input type="radio" name="size" value="<?= $s ?>"<?= $s === $p->size ? ' checked' : '' ?>><span><?= $s ?></span></label>
        <?php endforeach; ?></div></fieldset>
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
  <nav class="reader-dock" aria-label="<?= $this->e(\App\Lang::t('reader.focus_aria')) ?>">
    <a href="#contents" aria-label="<?= $this->e(\App\Lang::t('reader.contents')) ?>">≡</a>
    <a href="#text" aria-label="<?= $this->e(\App\Lang::t('reader.text')) ?>">Aa</a>
    <a href="/story/read/<?= $this->e($story['slug']) ?>/<?= (int) $position ?>" aria-label="<?= $this->e(\App\Lang::t('reader.exit_focus')) ?>">×</a>
  </nav>
  <?php endif; ?>
</div>
