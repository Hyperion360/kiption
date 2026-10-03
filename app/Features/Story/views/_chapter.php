<?php // app/Features/Story/views/_chapter.php ?>
<?php /* The chapter unit shared byte-for-byte by the read page (story/read)
       and the fragment endpoint (story/fragment): ONE .chapter-unit carrying
       the h-entry article, the read beacon, and the chapter-end block. The
       fragment response is exactly this unit; the read page wraps it in the
       reader shell. The article stamps its own story-span (data-p-start /
       data-p-end: the position module reads per-article spans) and the next
       fragment URL (data-next-url, the infinite module's fetch chain); the
       unit adds data-read-url for history. Story data only, so the cached
       guest bytes stay stable. The next-chapter link inside chapter-end
       stays the noscript path - a read URL, never a fragment URL.
       The unit-state template closes the unit: inert markup the infinite
       module swaps into the reader chrome when this chapter becomes the one
       being read (prev/next/focus/exit/Text URLs for the keys module and the
       Text form's return_to, the bar's chapter count, the visible chapter
       label and the focus breadcrumb's chapter-of text, both bookmark
       controls in their member or guest shape, and for members the progress
       URL). Everything in it is server-built. */
     $readUrl = static fn (int $n): string => '/story/read/' . $story['slug'] . '/' . $n;
     $unitRead = $readUrl((int) $position);
     $bmArgs = ['member' => !empty($member), 'story' => $story, 'position' => $position, 'bookmarked' => !empty($bookmarked), 'csrf' => $csrf];
     $unitRoman = \App\Features\Reader\Roman::numeral((int) $position);
     $unitRawTitle = (string) ($chapter['title'] ?? '');
     $unitTitle = $unitRawTitle !== '' ? $unitRawTitle : \App\Lang::t('story.chapter_n', ['n' => (int) $position]);
     $unitEndLabel = $unitRawTitle !== ''
         ? \App\Lang::t('reader.end_of_chapter_titled', ['n' => $unitRoman, 'title' => $unitRawTitle])
         : \App\Lang::t('reader.end_of_chapter', ['n' => $unitRoman]);
     $unitNextAttr = $next !== null ? ' data-next-url="' . $this->e('/story/fragment/' . $story['slug'] . '/' . (int) $next) . '"' : ''; ?>
<div class="chapter-unit" data-read-url="<?= $this->e($unitRead) ?>"<?= $unitNextAttr ?>>
  <article class="h-entry" data-p-start="<?= (int) $pct_start ?>" data-p-end="<?= (int) $pct_end ?>"<?= $unitNextAttr ?>>
    <?php /* the byline/dates breadcrumb: the comp drops it visually; the row stays for the mf2 time elements MicroformatsTest pins */ ?>
    <header class="chapter-meta visually-hidden">
      <a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a>
      <?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
      | <?= \App\Lang::t('story.chapter_of', ['n' => $position, 'm' => $total]) ?>
      | <?= \App\Lang::t('story.published') ?> <time class="dt-published" datetime="<?= $this->e($story['created_at']) ?>"><?= $this->e(substr((string) $story['created_at'], 0, 10)) ?></time>
      | <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time>
    </header>
    <div class="prose e-content">
      <div class="ch-kicker"><?= $this->e(\App\Lang::t('story.chapter_n', ['n' => $unitRoman])) ?></div>
      <h1 class="p-name"><?= $this->e($unitTitle) ?></h1>
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
  <div class="chapter-end" role="group" aria-label="<?= $this->e($unitEndLabel) ?>">
    <span class="dots" aria-hidden="true"></span>
    <p><?= $this->e($unitEndLabel) ?></p>
    <div class="chapter-end-actions">
      <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
        <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
        <button type="submit"><?= \App\Lang::t('story.leave_kudos') ?></button>
      </form>
      <a href="/story/view/<?= $this->e($story['slug']) ?>#reviews"><?= $this->e(\App\Lang::t('reader.review_link')) ?> · <?= (int) ($story['review_count'] ?? 0) ?></a>
    </div>
    <?php if ($next !== null):
        $unitNextTitle = ($titles[(int) $next] ?? '') !== '' ? $titles[(int) $next] : \App\Lang::t('story.chapter_n', ['n' => (int) $next]); ?>
    <div class="next-chapter">
      <a href="<?= $this->e($readUrl((int) $next)) ?>">
        <span class="ch-kicker"><?= $this->e(\App\Lang::t('reader.continues', ['roman' => \App\Features\Reader\Roman::numeral((int) $next)])) ?></span>
        <span class="next-title"><?= $this->e($unitNextTitle) ?></span>
      </a>
    </div>
    <?php endif; ?>
  </div>
  <template class="unit-state" data-position="<?= (int) $position ?>" data-prev="<?= isset($prev) && $prev !== null ? $this->e($readUrl((int) $prev)) : '' ?>" data-next="<?= $next !== null ? $this->e($readUrl((int) $next)) : '' ?>" data-focus-url="<?= $this->e($unitRead . '?focus=1') ?>" data-exit-focus="<?= $this->e($unitRead) ?>" data-text-url="<?= $this->e($unitRead . '#text') ?>" data-pct-focus="<?= $this->e(\App\Lang::t('story.chapter_of_pct', ['n' => (int) $position, 'm' => (int) $total, 'pct' => (int) $pct_start])) ?>" data-status="<?= $this->e(\App\Lang::t('story.chapter_of', ['n' => (int) $position, 'm' => (int) $total]) . ($unitRawTitle !== '' ? ' · ' . $unitRawTitle : '')) ?>"<?= !empty($member) ? ' data-progress-url="/reader/progress/' . $this->e($story['slug']) . '/' . (int) $position . '"' : '' ?>>
    <div data-slot="label"><span class="rt-chapter"><?= $this->e($unitRawTitle !== '' ? $unitRoman . ' · ' . $unitRawTitle : \App\Lang::t('story.chapter_n', ['n' => $unitRoman])) ?></span></div>
    <span class="bar-count"><?= (int) $position ?> / <?= (int) $total ?></span>
    <div data-slot="rt"><?= $this->render('story/_bookmark_control', ['variant' => 'rt'] + $bmArgs) ?></div>
    <div data-slot="bar"><?= $this->render('story/_bookmark_control', ['variant' => 'bar'] + $bmArgs) ?></div>
  </template>
</div>
