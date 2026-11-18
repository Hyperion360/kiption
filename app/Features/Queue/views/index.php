<?php // app/views/queue/index.php ?>
<?php $this->layout('layout'); ?>
<section class="queue-page">
  <h1><?= \App\Lang::t('queue.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('queue.intro') ?></p>
  <p class="queue-keys" data-js-module="queue-keys" hidden aria-label="<?= $this->e(\App\Lang::t('queue.keys_aria')) ?>"><kbd>J</kbd><kbd>K</kbd> <?= \App\Lang::t('queue.key_move') ?> <kbd>A</kbd> <?= \App\Lang::t('queue.key_approve') ?> <kbd>R</kbd> <?= \App\Lang::t('queue.key_remove') ?></p>
  <?php
  $groups = ['stories' => [], 'chapters' => [], 'members' => [], 'reports' => []];
  $kinds = ['story' => 'stories', 'chapter' => 'chapters', 'member' => 'members', 'report' => 'reports'];
  foreach ($rows as $r) { if ($r['k'] !== '0gate') $groups[$kinds[$r['k']]][] = $r; }
  // The tab asked for, else the first one with something waiting.
  if (!isset($groups[$tab])) {
      $tab = 'stories';
      foreach ($groups as $name => $list) { if ($list !== []) { $tab = $name; break; } }
  }
  $list = $groups[$tab];
  $current = $list[0] ?? null;
  foreach ($list as $r) { if ((int) $r['a'] === $item) { $current = $r; break; } }
  // What is known about the author, so the decision needs no second screen.
  $known = function (?string $packed): ?array {
      $p = explode('|', (string) $packed);
      return count($p) === 4 ? ['since' => $p[0], 'posted' => (int) $p[1], 'removed' => (int) $p[2], 'reports' => (int) $p[3]] : null;
  };
  $about = function (?string $packed) use ($known): array {
      $k = $known($packed);
      if ($k === null) return [];
      $n = fn (int $c, string $key): string => \App\Lang::t($c === 1 ? $key . '_one' : $key, ['n' => $c]);
      $bits = [[\App\Lang::t('queue.ctx_since', ['date' => $this->e(\App\Dates::format($k['since']))]), false]];
      $bits[] = [$k['posted'] === 0 ? \App\Lang::t('queue.ctx_first') : $n($k['posted'], 'queue.ctx_posted'), false];
      if ($k['removed'] !== 0) $bits[] = [$n($k['removed'], 'queue.ctx_removed'), true];
      if ($k['reports'] !== 0) $bits[] = [$n($k['reports'], 'queue.ctx_reports'), true];
      return $bits;
  };
  // The name each row goes by in the list.
  $name = fn (array $r): string => match ($r['k']) {
      'chapter' => $r['b'] !== '' ? $r['b'] : \App\Lang::t('story.untitled'),
      'report' => mb_strimwidth((string) $r['b'], 0, 60, '...'),
      default => (string) $r['b'],
  };
  ?>
  <nav class="queue-tabs" aria-label="<?= $this->e(\App\Lang::t('queue.tabs_aria')) ?>">
    <?php foreach ($groups as $t => $g): ?>
    <a href="/queue?tab=<?= $t ?>"<?= $t === $tab ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('queue.tab_' . $t) ?> <span class="queue-tab-n"><?= count($g) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($list === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('queue.empty_tab') ?></p>
  <?php else: ?>
  <div class="queue-split">
    <ul class="queue-list" aria-label="<?= $this->e(\App\Lang::t('queue.list_aria')) ?>">
      <?php foreach ($list as $r): $on = $current !== null && (int) $r['a'] === (int) $current['a']; ?>
      <li><a href="/queue?tab=<?= $tab ?>&amp;item=<?= (int) $r['a'] ?>"<?= $on ? ' aria-current="true"' : '' ?>>
        <span class="queue-list-top"><strong><?= $this->e($name($r)) ?></strong><span class="queue-when"><?= $this->e(\App\Dates::ago((string) $r['e'])) ?></span></span>
        <?php if ($r['k'] === 'story' || $r['k'] === 'chapter'): ?>
        <span class="queue-list-meta"><?= \App\Lang::t('story.by') ?> <strong><?= $this->e($r['d']) ?></strong><?php if ((int) $r['h'] > 0): ?> · <?= \App\Lang::t('queue.words', ['n' => number_format((int) $r['h'])]) ?><?php endif; ?></span>
        <span class="queue-list-facts"><?php foreach ($about($r['f']) as [$fact, $warn]): ?><span<?= $warn ? ' class="ctx-warn"' : '' ?>><?= $fact ?></span> <?php endforeach; ?></span>
        <?php else: ?>
        <span class="queue-list-meta"><?= $this->e($r['c']) ?></span>
        <?php endif; ?>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php $r = $current; ?>
    <article class="queue-detail" aria-label="<?= $this->e(\App\Lang::t('queue.detail_aria')) ?>">
      <?php if ($r['k'] === 'story'): ?>
      <p class="queue-eyebrow"><?= \App\Lang::t('queue.eyebrow_story') ?> · <?= \App\Lang::t('queue.sent', ['when' => $this->e(\App\Dates::ago((string) $r['e']))]) ?></p>
      <?php endif; ?>
      <h2><?= $this->e($name($r)) ?></h2>
      <?php if ($r['k'] === 'story' || $r['k'] === 'chapter'): ?>
      <?php
      $bits = [];
      if ($r['k'] === 'story') {
          foreach (explode("\x1f", (string) $r['p']) as $genre) { if ($genre !== '') $bits[] = $this->e($genre); }
          $bits[] = \App\Lang::t((int) $r['n'] === 1 ? 'story.complete' : 'story.wip');
      }
      if ((int) $r['h'] > 0) $bits[] = \App\Lang::t('queue.words', ['n' => number_format((int) $r['h'])]);
      if ($r['k'] === 'story') {
          $bits[] = \App\Lang::t((int) $r['o'] === 1 ? 'queue.chapters_n_one' : 'queue.chapters_n', ['n' => (int) $r['o']]);
          if ((string) $r['m'] !== '') $bits[] = $this->e((string) $r['m']);
      }
      ?>
      <p class="queue-what"><?php if ($r['k'] === 'chapter'): ?><?= \App\Lang::t('queue.in') ?> <a class="tap-link" href="/story/edit/<?= $this->e($r['c']) ?>"><?= $this->e((string) ($r['p'] ?? '') !== '' ? (string) $r['p'] : $r['c']) ?></a> <?php endif; ?><?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?><?= $bits === [] ? '' : ' · ' . implode(' · ', $bits) ?></p>
      <?php
      $tagNames = array_filter(explode("\x1f", (string) $r['i']), 'strlen');
      $newTags = array_filter(explode("\x1f", (string) $r['j']), 'strlen');
      ?>
      <?php if ($tagNames !== [] || $newTags !== []): ?>
      <ul class="queue-tags" aria-label="<?= $this->e(\App\Lang::t('queue.tags_aria')) ?>">
        <?php foreach ($tagNames as $tg): ?><li><span class="queue-tag"><?= $this->e($tg) ?></span></li><?php endforeach; ?>
        <?php foreach ($newTags as $tg): ?><li><span class="queue-tag queue-tag-new"><?= $this->e($tg) ?> · <?= \App\Lang::t('queue.new_tag_note') ?></span></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ((string) $r['g'] !== ''): ?>
      <h3><?= \App\Lang::t($r['k'] === 'story' ? 'queue.summary' : 'queue.opening') ?></h3>
      <p class="queue-excerpt"><?= $this->e((string) $r['g']) ?></p>
      <?php endif; ?>
      <?php if (($k = $known($r['f'])) !== null): ?>
      <dl class="queue-stats">
        <div><dt><?= \App\Lang::t('queue.stat_since') ?></dt><dd><?= $this->e(\App\Dates::format($k['since'])) ?></dd></div>
        <div><dt><?= \App\Lang::t('queue.stat_posted') ?></dt><dd><?= $k['posted'] === 0 ? \App\Lang::t('queue.stat_nothing') : $k['posted'] ?></dd></div>
        <div><dt><?= \App\Lang::t('queue.stat_reports') ?></dt><dd<?= $k['reports'] !== 0 ? ' class="ctx-warn"' : '' ?>><?= $k['reports'] === 0 ? \App\Lang::t('queue.stat_none') : $k['reports'] ?></dd></div>
      </dl>
      <?php endif; ?>
      <?php if ($r['k'] === 'story' && (string) $r['q'] !== ''): [$pt, $pb] = array_pad(explode("\x1f", (string) $r['q'], 2), 2, ''); ?>
      <h3 class="queue-preview-head"><?= \App\Lang::t($pt !== '' ? 'queue.chapter_preview' : 'queue.chapter_preview_bare', ['n' => 1, 'title' => $this->e($pt)]) ?></h3>
      <p class="queue-preview"><?= $this->e(trim($pb)) ?><?= mb_strlen($pb) >= 500 ? '...' : '' ?></p>
      <?php endif; ?>
      <div class="queue-actions">
        <form method="post" action="/queue/<?= $r['k'] ?>/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="btn-primary" data-queue-approve><?= \App\Lang::t('queue.approve') ?> <kbd class="queue-cap" aria-hidden="true">A</kbd></button>
        </form>
        <details class="queue-remove">
          <summary class="btn queue-remove-btn" data-queue-remove><?= \App\Lang::t('common.remove') ?> <kbd class="queue-cap" aria-hidden="true">R</kbd></summary>
          <form method="post" action="/queue/<?= $r['k'] ?>/<?= (int) $r['a'] ?>/remove" class="queue-remove-panel">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><input type="hidden" name="confirmed" value="1">
            <?php if ($r['k'] === 'story'): ?>
            <p class="queue-remove-head"><strong><?= \App\Lang::t('queue.remove_story_heading') ?></strong> <span class="queue-remove-note"><?= \App\Lang::t('queue.remove_note', ['name' => $this->e($r['d'])]) ?></span></p>
            <fieldset class="queue-reasons">
              <legend class="visually-hidden"><?= \App\Lang::t('queue.reason_pick') ?></legend>
              <?php foreach ($presets as $i => $key): ?><label><input type="radio" name="reason_preset" value="<?= $this->e($key) ?>" data-text="<?= $this->e(\App\Lang::t('queue.preset_' . $key)) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><?= \App\Lang::t('queue.preset_' . $key . '_label') ?></span></label><?php endforeach; ?>
              <label><input type="radio" name="reason_preset" value="" data-text=""><span><?= \App\Lang::t('queue.reason_other') ?></span></label>
            </fieldset>
            <label for="qr-reason" class="visually-hidden"><?= \App\Lang::t('queue.reason_more') ?></label>
            <textarea id="qr-reason" name="reason" maxlength="1000" rows="2" placeholder="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>"></textarea>
            <?php endif; ?>
            <p class="queue-remove-go"><button type="submit" class="btn-danger"><?= \App\Lang::t($r['k'] === 'story' ? 'queue.remove_story_btn' : 'queue.remove_confirm_btn') ?></button> <button type="button" class="btn" data-queue-cancel hidden><?= \App\Lang::t('common.cancel') ?></button></p>
          </form>
        </details>
        <a class="queue-open" href="/story/view/<?= $this->e($r['c']) ?>"><?= \App\Lang::t('queue.open_story') ?></a>
      </div>
      <?php elseif ($r['k'] === 'member'): ?>
      <p class="queue-what"><?= $this->e($r['c']) ?></p>
      <div class="queue-actions">
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="btn-primary"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <details class="queue-remove">
          <summary class="btn"><?= \App\Lang::t('queue.reject') ?></summary>
          <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/reject">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><input type="hidden" name="confirmed" value="1">
            <label for="qr-reason"><?= \App\Lang::t('queue.reason_more') ?></label>
            <input id="qr-reason" name="reason" maxlength="1000" placeholder="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>">
            <button type="submit"><?= \App\Lang::t('queue.reject') ?></button>
          </form>
        </details>
      </div>
      <?php else: ?>
      <?php if ($r['d'] !== null): ?><h3><?= \App\Lang::t('queue.report_text') ?></h3><blockquote class="chapter-meta"><?= $this->e($r['d']) ?></blockquote><?php endif; ?>
      <?php if ($r['c'] !== ''): ?><p class="queue-on"><?= \App\Lang::t('queue.on') ?> <a class="tap-link" href="/story/view/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a></p><?php endif; ?>
      <div class="queue-actions">
        <a class="btn" href="/report/remove/<?= (int) $r['a'] ?>"><?= \App\Lang::t('queue.remove_content') ?></a>
        <form method="post" action="/report/resolve/<?= (int) $r['a'] ?>/dismiss" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.dismiss') ?></button>
        </form>
      </div>
      <?php endif; ?>
    </article>
  </div>
  <?php endif; ?>
</section>
