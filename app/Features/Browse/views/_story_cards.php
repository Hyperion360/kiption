<?php // app/Features/Browse/views/_story_cards.php ?>
<?php // The recent listing's card loop, extracted verbatim (Task 6): the
     // full page includes it inside the story-list <ul> and ?fragment=1
     // renders it alone for infinite scroll, so page and fragment share
     // the exact same card bytes. Every <li> closes; the pill markup
     // exists only when the viewer's reading_history row does, so guest
     // bytes stay reader-neutral. The freshness dot renders only when the
     // caller passes 'fresh' - every member listing render does (recent page
     // and its fragment, category page and its fragment, the home shelves);
     // the language listing and search results deliberately skip it - those
     // are their own information graphics, not the recency list. ?>
<?php foreach ($stories as $s): ?>
  <?php $pen = (string) $s['penname'] !== '' ? (string) $s['penname'] : \App\Lang::t('adminmembers.no_penname');
        $inSearch = !empty($searchCard);
        $pill = ($s['last_position'] ?? null) !== null
            ? '<a class="continue-pill" href="/story/read/' . $this->e($s['slug']) . '/' . (int) $s['last_position'] . '">' . \App\Lang::t('browse.continue_pill', ['roman' => \App\Features\Reader\Roman::numeral((int) $s['last_position']), 'pct' => (int) $s['read_pct']]) . '</a>'
            : '';
        /* M6 meta: rating · status · words · categories (the cats_blob
         fold the listing query already carries); the update date moves to
         the title attribute of a time element so the line stays the comp's. */
        $cardCats = [];
        foreach ((is_array($cc = json_decode((string) ($s['cats_blob'] ?? ''), true)) ? $cc : []) as $c) {
            if (is_array($c) && isset($c['name'])) $cardCats[] = (string) $c['name'];
        }
        $metaLine = ($s['rating_label'] === null ? '' : $this->e($s['rating_label']) . ' · ')
            . \App\Lang::t($s['completed'] ? 'story.complete' : 'story.wip') . ' · '
            . number_format((int) $s['word_count']) . ' ' . \App\Lang::t('story.words')
            . ($cardCats !== [] ? ' · ' . $this->e(implode(', ', $cardCats)) : '');
        $updated = '<time class="card-updated" datetime="' . $this->e((string) $s['updated_at']) . '">' . \App\Lang::t('story.updated', ['date' => $this->e(\App\Dates::format((string) $s['updated_at']))]) . '</time>'; ?>
  <li class="story-card">
    <?php if ($inSearch): ?>
    <div class="card-head"><a class="story-title" href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a><?= $pill ?></div>
    <p class="byline"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($pen) ?></strong> <span class="byline-meta">· <?= $metaLine ?></span> <?= $updated ?></p>
    <?php else: ?>
    <a class="story-title" href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <p class="byline"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($pen) ?></strong></p>
    <p class="meta"><?php if (!empty($fresh) && \App\Freshness::fresh($s['updated_at'] ?? null)): ?><span class="fresh-dot" aria-hidden="true"></span> <?php endif; ?><?= $s['rating_label'] === null ? '' : $this->e($s['rating_label']) . ' · ' ?><span class="badge"><?= \App\Lang::t($s['completed'] ? 'story.complete' : 'story.wip') ?></span> ·
      <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?><?php if ($cardCats !== []): ?> · <?= $this->e(implode(', ', $cardCats)) ?><?php endif; ?>
      <?= $updated ?></p>
    <?php endif; ?>
    <?php
      // Why it matched: the first chapter that holds the words, else the summary, else a tag.
      $pq = (string) ($passageQuery ?? ''); $summaryHit = null; $where = null; $boxSegs = null;
      if ($pq !== '') {
          $ch = is_array($s['chapter'] ?? null) ? $s['chapter'] : null;
          if ($ch !== null) {
              $boxSegs = \App\Search\Passage::around((string) $ch['text'], $pq)
                  ?? [['text' => mb_substr((string) $ch['text'], 0, 160, 'UTF-8'), 'hit' => false]];
              $roman = \App\Features\Reader\Roman::numeral((int) $ch['position']);
              $where = trim((string) ($ch['title'] ?? '')) === ''
                  ? \App\Lang::t('search.where_chapter_bare', ['roman' => $roman])
                  : \App\Lang::t('search.where_chapter', ['roman' => $roman, 'title' => trim((string) $ch['title'])]);
          } elseif (($summaryHit = \App\Search\Passage::around((string) $s['summary'], $pq)) === null && ($s['tags'] ?? '') !== '') {
              foreach (explode("\x1f", (string) $s['tags']) as $tagName) {
                  if (($hit = \App\Search\Passage::around($tagName, $pq, 80)) !== null) {
                      $boxSegs = $hit; $where = \App\Lang::t('search.where_tag'); break;
                  }
              }
          }
      }
    ?>
    <?php if ($summaryHit !== null): ?>
    <p class="summary clamp-2 passage"><?php foreach ($summaryHit as $seg): ?><?= $seg['hit'] ? '<mark>' . $this->e($seg['text']) . '</mark>' : $this->e($seg['text']) ?><?php endforeach; ?></p>
    <?php else: ?>
    <p class="summary clamp-2"><?= $this->e($s['summary']) ?></p>
    <?php endif; ?>
    <?php if ($boxSegs !== null): ?>
    <p class="passage-where"><span class="passage-label"><?= $this->e($where) ?></span> <span class="passage"><?php foreach ($boxSegs as $seg): ?><?= $seg['hit'] ? '<mark>' . $this->e($seg['text']) . '</mark>' : $this->e($seg['text']) ?><?php endforeach; ?></span></p>
    <?php endif; ?>
    <?php if (!$inSearch) echo $pill; ?>
  </li>
<?php endforeach; ?>
