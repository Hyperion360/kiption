<?php // app/views/story/whole.php ?>
<?php // C9 (frame M3): every chapter closes with the boundary separator (dots,
     // roman caption, kudos form, review link) and every later chapter opens
     // with the comp's next-chapter kicker above its h2.p-name. The anchors,
     // the print link, the noindex behavior, and the beacon's absence are
     // pinned by WholeViewTest and stay byte-identical.
     $this->layout('layout');
     $roman = static fn (array $c): string => \App\Features\Reader\Roman::numeral((int) $c['position']); ?>
<?php /* The whole-work reading view doubles as the print view: the media="print"
   stylesheet is pure CSS plus the browser print command (no JS, no ?print=
   URL variant). The read beacon deliberately does NOT embed here: reads count
   per chapter only, and the whole view is not a chapter read. */ ?>
<link rel="stylesheet" media="print" href="/assets/print.css">
<article class="h-entry whole-work">
  <header class="whole-head">
    <p class="eyebrow"><?= \App\Lang::t('story.whole_link') ?></p>
    <h1><a class="u-url" href="/story/view/<?= $this->e($story['slug']) ?>"><?= $this->e($story['title']) ?></a></h1>
    <p class="meta"><?= \App\Lang::t('story.by') ?> <span class="p-author h-card"><?= $this->e($story['penname']) ?></span>
      · <?= $this->e($story['rating_label']) ?><?php if ((int) $story['is_adult'] === 1): ?> <span class="badge"><?= \App\Lang::t('story.adult') ?></span><?php endif; ?>
      · <?= number_format((int) $story['word_count']) ?> <?= \App\Lang::t('story.words') ?>
      · <?= \App\Lang::t('story.updated_label') ?> <time class="dt-updated" datetime="<?= $this->e($story['updated_at']) ?>"><?= $this->e(substr((string) $story['updated_at'], 0, 10)) ?></time></p>
    <span class="print-hint"><?= \App\Lang::t('story.whole_print_hint') ?></span>
  </header>
  <nav class="whole-toc" aria-label="<?= $this->e(\App\Lang::t('story.whole_toc_aria')) ?>">
    <h2><?= \App\Lang::t('story.whole_toc') ?></h2>
    <ol class="chapter-list">
      <?php foreach ($chapters as $c): ?>
      <li><a href="#ch-<?= (int) $c['position'] ?>"><span class="ch-num"><?= $roman($c) ?></span><span class="ch-title"><?= $this->e($c['title'] !== '' ? $c['title'] : \App\Lang::t('story.chapter_n', ['n' => (int) $c['position']])) ?></span><span class="ch-meta"><?= number_format((int) ($c['word_count'] ?? 0)) ?></span></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <?php foreach ($chapters as $i => $c): ?>
  <section class="prose" id="ch-<?= (int) $c['position'] ?>">
    <?php if ($i > 0): ?><p class="ch-kicker"><?= \App\Lang::t('story.chapter_n', ['n' => $roman($c)]) ?></p><?php endif; ?>
    <h2 class="p-name"><?= $this->e($c['title'] !== '' ? $c['title'] : \App\Lang::t('story.chapter_n', ['n' => (int) $c['position']])) ?></h2>
    <?= \App\Markdown::render($c['content']) /* markdown at rest; raw HTML cannot be stored */ ?>
  </section>
  <div class="chapter-end" role="group" aria-label="<?= $this->e(\App\Lang::t('reader.end_of_chapter', ['n' => $roman($c)])) ?>">
    <span class="dots" aria-hidden="true"></span>
    <p><?= \App\Lang::t('reader.end_of_chapter', ['n' => $roman($c)]) ?></p>
    <div class="chapter-end-actions">
      <form method="post" action="/kudos/add/<?= $this->e($story['slug']) ?>" class="inline">
        <?php if (!empty($csrf)): ?><input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><?php endif; ?>
        <button type="submit"><?= \App\Lang::t('story.leave_kudos') ?></button>
      </form>
      <a href="/story/view/<?= $this->e($story['slug']) ?>#reviews"><?= \App\Lang::t('reader.review_link') ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</article>
