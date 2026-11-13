<?php // app/Features/Manage/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1><?= \App\Lang::t('manage.heading') ?></h1>
<p class="lede"><?= \App\Lang::t('manage.intro') ?></p>
<?php if ($stillOwner): ?>
<p class="admin-help"><?= $this->e(\App\Lang::t('manage.pick_penname')) ?> <a href="/account/settings#penname"><?= $this->e(\App\Lang::t('manage.pick_penname_link')) ?></a></p>
<?php endif; ?>
<p class="admin-quick"><a class="btn" href="/story/new"><?= \App\Lang::t('nav.post_story') ?></a></p>
<?php
$queue = $waiting['stories'] + $waiting['chapters'];
$cards = [
    ['n' => $queue, 'label' => $queue === 1 ? 'manage.wait_story' : 'manage.wait_stories', 'url' => '/queue', 'urgent' => $queue > 0],
    ['n' => $waiting['members'], 'label' => $waiting['members'] === 1 ? 'manage.wait_member' : 'manage.wait_members', 'url' => '/queue', 'urgent' => $waiting['members'] > 0],
    ['n' => $waiting['reports'], 'label' => $waiting['reports'] === 1 ? 'manage.wait_report' : 'manage.wait_reports', 'url' => '/queue', 'urgent' => $waiting['reports'] > 0],
    ['n' => $waiting['new'], 'label' => $waiting['new'] === 1 ? 'manage.new_member' : 'manage.new_members', 'url' => '/adminmembers', 'urgent' => false],
];
$todo = $queue + $waiting['members'] + $waiting['reports'];
?>
<section aria-labelledby="manage-now" class="home-now">
  <h2 id="manage-now"><?= \App\Lang::t('manage.now') ?></h2>
  <p class="home-status<?= $todo > 0 ? ' is-waiting' : '' ?>"><?= \App\Lang::t($todo > 0 ? 'manage.now_waiting' : 'manage.now_clear') ?></p>
  <ul class="home-cards">
    <?php foreach ($cards as $card): ?>
    <li><a href="<?= $card['url'] ?>"<?= $card['urgent'] ? ' class="is-urgent"' : '' ?>><span class="home-n"><?= (int) $card['n'] ?></span> <span class="home-label"><?= \App\Lang::t($card['label']) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<section aria-labelledby="manage-recent" class="home-recent">
  <h2 id="manage-recent"><?= \App\Lang::t('manage.recent') ?></h2>
  <?php if ($recent === []): ?>
  <p class="chapter-meta"><?= \App\Lang::t('manage.recent_none') ?></p>
  <?php else: ?>
  <ul class="home-log">
    <?php foreach ($recent as $r): ?>
    <li><span class="home-log-what"><?= $this->e(\App\Lang::t('activity.a.' . $r['b'])) ?><?= (string) $r['c'] !== '' ? ': ' . $this->e($r['c']) : '' ?></span>
      <span class="home-log-meta"><?= $this->e((string) $r['a'] !== '' ? $r['a'] : \App\Lang::t($r['n'] !== null ? 'adminmembers.no_penname' : 'activity.unknown')) ?>, <time datetime="<?= $this->e($r['d']) ?>"><?= $this->e(\App\Dates::format((string) $r['d'], false, true)) ?></time></span></li>
    <?php endforeach; ?>
  </ul>
  <p><a href="/activity"><?= \App\Lang::t('manage.recent_all') ?></a></p>
  <?php endif; ?>
</section>
<h2 class="home-all"><?= \App\Lang::t('manage.all_screens') ?></h2>
<?php foreach ($groupNames as $gid => $gkey): if (empty($groups[$gid])) continue; ?>
<section aria-labelledby="manage-<?= $this->e($gid) ?>">
  <h3 id="manage-<?= $this->e($gid) ?>"><?= \App\Lang::t($gkey) ?></h3>
  <ul class="manage-list">
    <?php foreach ($groups[$gid] as $l): ?>
    <li><a href="<?= $this->e($l['url']) ?>"><span class="manage-name"><?= \App\Lang::t($l['label']) ?></span> <span class="manage-what"><?= \App\Lang::t($l['label'] . '_what') ?></span></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<?php if ($database): ?>
<section aria-labelledby="manage-advanced">
  <h3 id="manage-advanced"><?= \App\Lang::t('manage.group_advanced') ?></h3>
  <ul class="manage-list">
    <li><a href="/admin"><span class="manage-name"><?= \App\Lang::t('manage.database') ?></span> <span class="manage-what"><?= \App\Lang::t('manage.database_warning') ?></span></a></li>
  </ul>
</section>
<?php endif; ?>
