<?php // app/views/analytics/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('analytics.heading') ?></h1>
<p class="chapter-meta"><?= \App\Lang::t('analytics.approx_note') ?></p>
<section>
  <h2><?= \App\Lang::t('analytics.totals') ?></h2>
  <table>
    <tbody>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_stories') ?></th><td><?= number_format($totals['stories']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_chapters') ?></th><td><?= number_format($totals['chapters']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_members') ?></th><td><?= number_format($totals['members']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_reviews') ?></th><td><?= number_format($totals['reviews']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_kudos') ?></th><td><?= number_format($totals['kudos']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_favorites') ?></th><td><?= number_format($totals['favorites']) ?></td></tr>
      <tr><th scope="row"><?= \App\Lang::t('analytics.total_reads') ?></th><td><?= number_format($totals['reads']) ?></td></tr>
    </tbody>
  </table>
</section>
<section>
  <h2><?= \App\Lang::t('analytics.reads_by_day') ?></h2>
<?php if ($readsByDay === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('analytics.empty') ?></p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col"><?= \App\Lang::t('analytics.day') ?></th><th scope="col"><?= \App\Lang::t('analytics.reads') ?></th></tr></thead>
    <tbody>
<?php foreach ($readsByDay as $r): ?>
      <tr><td><?= $this->e($r['day']) ?></td><td><?= number_format($r['count']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<section>
  <h2><?= \App\Lang::t('analytics.kudos_by_day') ?></h2>
<?php if ($kudosByDay === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('analytics.empty') ?></p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col"><?= \App\Lang::t('analytics.day') ?></th><th scope="col"><?= \App\Lang::t('analytics.kudos') ?></th></tr></thead>
    <tbody>
<?php foreach ($kudosByDay as $r): ?>
      <tr><td><?= $this->e($r['day']) ?></td><td><?= number_format($r['count']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<section>
  <h2><?= \App\Lang::t('analytics.favorites_by_day') ?></h2>
<?php if ($favoritesByDay === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('analytics.empty') ?></p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col"><?= \App\Lang::t('analytics.day') ?></th><th scope="col"><?= \App\Lang::t('analytics.favorites') ?></th></tr></thead>
    <tbody>
<?php foreach ($favoritesByDay as $r): ?>
      <tr><td><?= $this->e($r['day']) ?></td><td><?= number_format($r['count']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<section>
  <h2><?= \App\Lang::t('analytics.members_by_day') ?></h2>
<?php if ($membersByDay === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('analytics.empty') ?></p>
<?php else: ?>
  <table>
    <thead><tr><th scope="col"><?= \App\Lang::t('analytics.day') ?></th><th scope="col"><?= \App\Lang::t('analytics.new_members') ?></th></tr></thead>
    <tbody>
<?php foreach ($membersByDay as $r): ?>
      <tr><td><?= $this->e($r['day']) ?></td><td><?= number_format($r['count']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
<section>
  <h2><?= \App\Lang::t('analytics.top_stories') ?></h2>
<?php if ($top === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('analytics.empty') ?></p>
<?php else: ?>
  <ol>
<?php foreach ($top as $t): ?>
    <li><a href="/story/view/<?= $this->e($t['slug']) ?>"><?= $this->e($t['title']) ?></a> <span class="meta"><?= \App\Lang::t('analytics.top_count', ['n' => $t['count']]) ?></span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
