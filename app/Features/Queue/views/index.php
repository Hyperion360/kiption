<?php // app/views/queue/index.php ?>
<?php $this->layout('layout'); ?>
<section class="queue-page">
  <h1><?= \App\Lang::t('queue.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('queue.intro') ?></p>
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
  $about = function (?string $packed): array {
      $p = explode('|', (string) $packed);
      if (count($p) !== 4) return [];
      [$since, $posted, $removed, $reports] = [$p[0], (int) $p[1], (int) $p[2], (int) $p[3]];
      $n = fn (int $c, string $key): string => \App\Lang::t($c === 1 ? $key . '_one' : $key, ['n' => $c]);
      $bits = [[\App\Lang::t('queue.ctx_since', ['date' => $this->e(\App\Dates::format($since))]), false]];
      $bits[] = [$posted === 0 ? \App\Lang::t('queue.ctx_first') : $n($posted, 'queue.ctx_posted'), false];
      if ($removed !== 0) $bits[] = [$n($removed, 'queue.ctx_removed'), true];
      if ($reports !== 0) $bits[] = [$n($reports, 'queue.ctx_reports'), true];
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
        <strong><?= $this->e($name($r)) ?></strong>
        <span class="queue-list-meta"><?= $r['k'] === 'member' ? $this->e($r['c']) : ($r['k'] === 'report' ? $this->e($r['c']) : $this->e($r['d'])) ?></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
    <?php $r = $current; ?>
    <article class="queue-detail" aria-label="<?= $this->e(\App\Lang::t('queue.detail_aria')) ?>">
      <h2><?= $this->e($name($r)) ?></h2>
      <?php if ($r['k'] === 'story' || $r['k'] === 'chapter'): ?>
      <p class="queue-what"><?php if ($r['k'] === 'chapter'): ?><?= \App\Lang::t('queue.in') ?> <a class="tap-link" href="/story/edit/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a> <?php endif; ?><?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?><?php if ((int) $r['h'] > 0): ?>, <?= \App\Lang::t('queue.words', ['n' => number_format((int) $r['h'])]) ?><?php endif; ?></p>
      <?php if ((string) $r['g'] !== ''): ?>
      <h3><?= \App\Lang::t($r['k'] === 'story' ? 'queue.summary' : 'queue.opening') ?></h3>
      <p class="queue-excerpt"><?= $this->e((string) $r['g']) ?></p>
      <?php endif; ?>
      <p class="row-context"><?php foreach ($about($r['f']) as [$fact, $warn]): ?><span<?= $warn ? ' class="ctx-warn"' : '' ?>><?= $fact ?></span> <?php endforeach; ?></p>
      <div class="queue-actions">
        <form method="post" action="/queue/<?= $r['k'] ?>/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit" class="btn-primary"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <details class="queue-remove">
          <summary class="btn"><?= \App\Lang::t('common.remove') ?></summary>
          <form method="post" action="/queue/<?= $r['k'] ?>/<?= (int) $r['a'] ?>/remove">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><input type="hidden" name="confirmed" value="1">
            <?php if ($r['k'] === 'story'): ?>
            <label for="qr-preset"><?= \App\Lang::t('queue.reason_pick') ?></label>
            <select id="qr-preset" name="reason_preset">
              <option value=""><?= \App\Lang::t('queue.reason_none') ?></option>
              <?php foreach ($presets as $key): ?><option value="<?= $this->e($key) ?>"><?= \App\Lang::t('queue.preset_' . $key . '_label') ?></option><?php endforeach; ?>
            </select>
            <label for="qr-reason"><?= \App\Lang::t('queue.reason_more') ?></label>
            <input id="qr-reason" name="reason" maxlength="1000" placeholder="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>">
            <?php endif; ?>
            <button type="submit"><?= \App\Lang::t('queue.remove_confirm_btn') ?></button>
          </form>
        </details>
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
