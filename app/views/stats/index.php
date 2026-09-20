<?php // app/views/stats/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('stats.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('stats.approx_note') ?></p>
  <?php if ($rows === []): ?>
    <p class="chapter-meta"><?= \App\Lang::t('stats.empty') ?></p>
  <?php else: ?>
  <table>
    <thead><tr>
      <th scope="col"><?= \App\Lang::t('stats.story') ?></th>
      <th scope="col"><?= \App\Lang::t('stats.status') ?></th>
      <th scope="col"><?= \App\Lang::t('stats.reads') ?></th>
      <th scope="col"><?= \App\Lang::t('stats.reads_30') ?></th>
      <th scope="col"><?= \App\Lang::t('stats.kudos') ?></th>
      <th scope="col"><?= \App\Lang::t('stats.favorites') ?></th>
    </tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/story/edit/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a></td>
        <td class="chapter-meta"><?= $r['validated'] === 1 ? \App\Lang::t('account.published') : \App\Lang::t('story.awaiting_validation') ?></td>
        <td><?= number_format($r['total_reads']) ?></td>
        <td><?= number_format($r['month_reads']) ?></td>
        <td><?= number_format($r['kudos']) ?></td>
        <td><?= number_format($r['favorites']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
