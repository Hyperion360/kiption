<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('activity.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('activity.lede') ?></p>
  <p class="chapter-meta"><?= \App\Lang::t('activity.retention') ?></p>
  <form method="get" action="/activity" class="inline log-filter">
    <label><span><?= \App\Lang::t('activity.filter_who') ?></span>
      <input type="search" name="who" maxlength="60" value="<?= $this->e($who) ?>" placeholder="<?= $this->e(\App\Lang::t('activity.filter_who_hint')) ?>"></label>
    <label><span><?= \App\Lang::t('activity.filter_kind') ?></span>
      <select name="kind">
        <option value=""><?= \App\Lang::t('activity.kind_all') ?></option>
        <?php foreach ($kinds as $k): ?>
        <option value="<?= $this->e($k) ?>"<?= $k === $kind ? ' selected' : '' ?>><?= \App\Lang::t('activity.kind_' . $k) ?></option>
        <?php endforeach; ?>
      </select></label>
    <button type="submit"><?= \App\Lang::t('activity.filter_go') ?></button>
    <?php if ($filter !== ''): ?><a href="/activity" class="btn"><?= \App\Lang::t('activity.filter_clear') ?></a><?php endif; ?>
  </form>
  <?php if ($days === []): ?>
  <p class="empty-state"><?= \App\Lang::t($filter !== '' ? 'activity.none_match' : 'activity.none') ?></p>
  <?php endif; ?>
  <?php
  $today = date('Y-m-d');
  $yesterday = date('Y-m-d', time() - 86400);
  // A known account with no penname yet is still that account, not a signed-out visitor.
  $name = fn (array $r): string => $this->e((string) $r['actor_name'] !== '' ? $r['actor_name'] : \App\Lang::t($r['actor_id'] !== null ? 'adminmembers.no_penname' : 'activity.unknown'));
  $clock = fn (array $r): string => $this->e(\App\Dates::time((string) $r['created_at']));
  ?>
  <?php foreach ($days as $day => $entries): ?>
  <h2 class="log-day"><?= $day === $today ? \App\Lang::t('activity.today') : ($day === $yesterday ? \App\Lang::t('activity.yesterday') : $this->e(\App\Dates::format($day))) ?></h2>
  <ul class="home-log log-list">
    <?php foreach ($entries as $burst): $r = $burst[0]; $what = $this->e(\App\Lang::t('activity.a.' . $r['action'])); ?>
    <?php if (count($burst) === 1): ?>
    <li><span class="home-log-what"><?= $what ?><?= (string) $r['detail'] !== '' ? ': ' . $this->e($r['detail']) : '' ?></span>
      <span class="home-log-meta"><?= $name($r) ?>, <time datetime="<?= $this->e($r['created_at']) ?>"><?= $clock($r) ?></time></span></li>
    <?php else: ?>
    <li class="log-burst"><details>
      <summary><span class="home-log-what"><?= \App\Lang::t('activity.times', ['what' => $what, 'n' => count($burst)]) ?></span>
        <span class="home-log-meta"><?= $name($r) ?>, <?= $clock($burst[count($burst) - 1]) ?> <?= \App\Lang::t('activity.to') ?> <?= $clock($r) ?></span></summary>
      <ul>
        <?php foreach ($burst as $one): ?>
        <li><time datetime="<?= $this->e($one['created_at']) ?>"><?= $clock($one) ?></time><?= (string) $one['detail'] !== '' ? ' ' . $this->e($one['detail']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </details></li>
    <?php endif; ?>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
  <?php $carry = $filter !== '' ? '&amp;' . $this->e($filter) : ''; ?>
  <?php if ($page > 1 || $hasMore): ?>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
    <?php if ($page > 1): ?><a href="/activity?page=<?= $page - 1 ?><?= $carry ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
    <?php if ($hasMore): ?><a class="pager-next" href="/activity?page=<?= $page + 1 ?><?= $carry ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
