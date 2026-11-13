<?php // app/views/queue/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('queue.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('queue.intro') ?></p>
  <?php
  $groups = ['story' => [], 'chapter' => [], 'member' => [], 'report' => []];
  foreach ($rows as $r) { if ($r['k'] !== '0gate') $groups[$r['k']][] = $r; }
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
  ?>
  <h2><?= \App\Lang::t('queue.stories') ?></h2>
  <?php if ($groups['story'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_waiting') ?></p><?php else: ?>
  <ul class="admin-rows queue-rows">
    <?php foreach ($groups['story'] as $r): ?>
      <li>
        <div class="queue-what"><strong><?= $this->e($r['b']) ?></strong> <?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?>
        <p class="row-context"><?php foreach ($about($r['f']) as [$fact, $warn]): ?><span<?= $warn ? ' class="ctx-warn"' : '' ?>><?= $fact ?></span> <?php endforeach; ?></p></div>
        <div class="queue-actions">
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/remove" class="inline" data-js-module="confirm" data-confirm="<?= $this->e(\App\Lang::t('queue.confirm_remove')) ?>">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <input name="reason" maxlength="1000" placeholder="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>">
          <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.chapters') ?></h2>
  <?php if ($groups['chapter'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_waiting') ?></p><?php else: ?>
  <ul class="admin-rows queue-rows">
    <?php foreach ($groups['chapter'] as $r): ?>
      <li>
        <div class="queue-what"><strong><?= $this->e($r['b'] !== '' ? $r['b'] : \App\Lang::t('story.untitled')) ?></strong> <?= \App\Lang::t('queue.in') ?> <a class="tap-link" href="/story/edit/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a> <?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?>
        <p class="row-context"><?php foreach ($about($r['f']) as [$fact, $warn]): ?><span<?= $warn ? ' class="ctx-warn"' : '' ?>><?= $fact ?></span> <?php endforeach; ?></p></div>
        <div class="queue-actions">
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/remove" class="inline" data-js-module="confirm" data-confirm="<?= $this->e(\App\Lang::t('queue.confirm_remove')) ?>">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.members') ?></h2>
  <?php if ($groups['member'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nobody') ?></p><?php else: ?>
  <ul class="admin-rows queue-rows">
    <?php foreach ($groups['member'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong> (<?= $this->e($r['c']) ?>)
        <div class="queue-actions">
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/reject" class="inline" data-js-module="confirm" data-confirm="<?= $this->e(\App\Lang::t('queue.confirm_reject')) ?>">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <input name="reason" maxlength="1000" placeholder="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>" aria-label="<?= $this->e(\App\Lang::t('queue.reason_placeholder')) ?>">
          <button type="submit"><?= \App\Lang::t('queue.reject') ?></button>
        </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.reports') ?></h2>
  <?php if ($groups['report'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_reported') ?></p><?php else: ?>
  <ul class="admin-rows queue-rows">
    <?php foreach ($groups['report'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong>
        <?php if ($r['d'] !== null): ?><blockquote class="chapter-meta"><?= $this->e($r['d']) ?></blockquote><?php endif; ?>
        <?php if ($r['c'] !== ''): ?><div class="queue-on"><?= \App\Lang::t('queue.on') ?> <a class="tap-link" href="/story/view/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a></div><?php endif; ?>
        <div class="queue-actions">
        <a class="btn" href="/report/remove/<?= (int) $r['a'] ?>"><?= \App\Lang::t('queue.remove_content') ?></a>
        <form method="post" action="/report/resolve/<?= (int) $r['a'] ?>/dismiss" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.dismiss') ?></button>
        </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
