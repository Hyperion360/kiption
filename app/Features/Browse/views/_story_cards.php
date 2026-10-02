<?php // app/Features/Browse/views/_story_cards.php ?>
<?php // The recent listing's card loop, extracted verbatim (Task 6): the
     // full page includes it inside the story-list <ul> and ?fragment=1
     // renders it alone for infinite scroll, so page and fragment share
     // the exact same card bytes. Every <li> closes; the pill markup
     // exists only when the viewer's reading_history row does, so guest
     // bytes stay reader-neutral. ?>
<?php foreach ($stories as $s): ?>
  <li class="story-card">
    <a class="story-title" href="/story/view/<?= $this->e($s['slug']) ?>"><?= $this->e($s['title']) ?></a>
    <p class="byline"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($s['penname']) ?></strong></p>
    <?php /* M6 meta: rating · status · words · categories (the cats_blob
         fold the listing query already carries); the update date moves to
         the title attribute of a time element so the line stays the comp's. */
         $cardCats = [];
         foreach ((is_array($cc = json_decode((string) ($s['cats_blob'] ?? ''), true)) ? $cc : []) as $c) {
             if (is_array($c) && isset($c['name'])) $cardCats[] = (string) $c['name'];
         } ?>
    <p class="meta"><?= $this->e($s['rating_label']) ?> ·
      <?php if ($s['completed']): ?><span class="badge"><?= \App\Lang::t('story.complete') ?></span><?php else: ?><span class="badge"><?= \App\Lang::t('story.wip') ?></span><?php endif; ?> ·
      <?= number_format((int) $s['word_count']) ?> <?= \App\Lang::t('story.words') ?><?php if ($cardCats !== []): ?> · <?= $this->e(implode(', ', $cardCats)) ?><?php endif; ?>
      <time class="card-updated" datetime="<?= $this->e((string) $s['updated_at']) ?>"><?= \App\Lang::t('story.updated', ['date' => $this->e(substr((string) $s['updated_at'], 0, 10))]) ?></time></p>
    <p class="summary clamp-2"><?= $this->e($s['summary']) ?></p>
    <?php if (($s['last_position'] ?? null) !== null): ?>
    <a class="continue-pill" href="/story/read/<?= $this->e($s['slug']) ?>/<?= (int) $s['last_position'] ?>"><?= \App\Lang::t('browse.continue_pill', ['roman' => \App\Features\Reader\Roman::numeral((int) $s['last_position']), 'pct' => (int) $s['read_pct']]) ?></a>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
