<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('activity.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('activity.lede') ?></p>
  <p class="chapter-meta"><?= \App\Lang::t('activity.retention') ?></p>
  <?php if ($rows === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('activity.none') ?></p>
  <?php else: ?>
  <table class="stack-table activity-table">
    <thead><tr>
      <th scope="col"><?= \App\Lang::t('activity.what') ?></th>
      <th scope="col"><?= \App\Lang::t('activity.who') ?></th>
      <th scope="col"><?= \App\Lang::t('activity.when') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $this->e(\App\Lang::t('activity.a.' . $r['action'])) ?><?= $r['detail'] !== '' ? ': ' . $this->e($r['detail']) : '' ?></td>
      <td data-label="<?= $this->e(\App\Lang::t('activity.who')) ?>"><?= $this->e($r['actor_name'] !== '' ? $r['actor_name'] : \App\Lang::t('activity.unknown')) ?></td>
      <td data-label="<?= $this->e(\App\Lang::t('activity.when')) ?>"><time datetime="<?= $this->e($r['created_at']) ?>"><?= $this->e(\App\Dates::format((string) $r['created_at'], true, true)) ?></time></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($page > 1 || $hasMore): ?>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
    <?php if ($page > 1): ?><a href="/activity?page=<?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
    <?php if ($hasMore): ?><a class="pager-next" href="/activity?page=<?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
