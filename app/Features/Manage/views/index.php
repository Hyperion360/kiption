<?php // app/Features/Manage/views/index.php ?>
<?php $this->layout('layout'); ?>
<?php
$queue = $waiting['stories'] + $waiting['chapters'];
$todo = $queue + $waiting['members'] + $waiting['reports'] + $waiting['tags'];
$ago = static fn (string $stamp): string => \App\Dates::ago($stamp);
// A few story titles, then how many more: "One, Two and Three", or "One, Two, Three and 2 more".
$titles = array_values(array_filter(explode("\x1f", (string) ($info['stories']['c'] ?? ''))));
$names = array_slice($titles, 0, 3);
if ($waiting['stories'] > count($names)) $names[] = \App\Lang::t('manage.preview_more', ['n' => $waiting['stories'] - count($names)]);
$last = array_pop($names);
$named = $names === [] ? (string) $last : implode(', ', $names) . ' ' . \App\Lang::t('manage.preview_and') . ' ' . $last;
$chapterBit = $waiting['chapters'] === 1 ? \App\Lang::t('manage.preview_plus_chapter') : ($waiting['chapters'] > 1 ? \App\Lang::t('manage.preview_plus_chapters', ['n' => $waiting['chapters']]) : '');
$storyAgo = $ago((string) min(array_filter([(string) ($info['stories']['a'] ?? ''), (string) ($info['chapters']['a'] ?? '')], static fn ($v) => $v !== '') ?: ['']));
$storyPreview = $waiting['stories'] > 0
    ? \App\Lang::t('manage.preview_stories', ['what' => $named . $chapterBit, 'ago' => $storyAgo])
    : \App\Lang::t('manage.preview_chapters_only', ['n' => $waiting['chapters'], 'ago' => $storyAgo]);
$memberName = (string) ($info['members']['a'] ?? '') !== '' ? (string) $info['members']['a'] : \App\Lang::t('adminmembers.no_penname');
$memberPreview = $waiting['members'] === 1
    ? \App\Lang::t('manage.preview_member', ['name' => $memberName, 'ago' => $ago((string) ($info['members']['d'] ?? ''))])
    : \App\Lang::t('manage.preview_members', ['name' => $memberName, 'ago' => $ago((string) ($info['members']['d'] ?? ''))]);
// With the owner's signup question on, a waiting member has passed it, so say so.
if ((string) ($info['members']['c'] ?? '0') === '1') $memberPreview = rtrim($memberPreview, '.') . ' ' . \App\Lang::t('manage.preview_answered');
// Up to two open reports, "One on Title (reason) and one on a review of Title", then how many more.
$reportBits = [];
foreach (array_filter(explode("\x1e", (string) ($info['reports']['c'] ?? ''))) as $packed) {
    [$kind, $what, $why] = array_pad(explode("\x1f", $packed), 3, '');
    $reportBits[] = \App\Lang::t($kind === 'r' ? 'manage.preview_report_review' : 'manage.preview_report_story', ['title' => $what]) . ($why !== '' ? ' (' . $why . ')' : '');
}
$reportPreview = $reportBits === []
    ? \App\Lang::t('manage.preview_reports', ['ago' => $ago((string) ($info['reports']['a'] ?? ''))])
    : ucfirst(implode(' ' . \App\Lang::t('manage.preview_and') . ' ', $reportBits))
        . ($waiting['reports'] > count($reportBits) ? ', ' . \App\Lang::t('manage.preview_plus_more', ['n' => $waiting['reports'] - count($reportBits)]) : '') . '.';
// The most suggested tag names, then how many more are waiting.
$tagBits = array_values(array_filter(explode("\x1f", (string) ($info['tags']['c'] ?? ''))));
if ($waiting['tags'] > count($tagBits) && $tagBits !== []) $tagBits[] = \App\Lang::t('manage.preview_more', ['n' => $waiting['tags'] - count($tagBits)]);
$tagLast = $tagBits === [] ? '' : array_pop($tagBits);
$tagPreview = $tagLast === '' ? \App\Lang::t('manage.preview_tags')
    : \App\Lang::t('manage.preview_tags_named', ['names' => ($tagBits === [] ? '' : implode(', ', $tagBits) . ' ' . \App\Lang::t('manage.preview_and') . ' ') . $tagLast]);
$posted = (int) ($info['new']['a'] ?? 0);
// Each row: how many, what, a line about it, and the one button that goes and does it.
$rows = [
    ['n' => $queue, 'one' => 'manage.wait_story', 'many' => 'manage.wait_stories', 'url' => '/queue', 'cta' => 'manage.cta_review', 'preview' => $queue > 0 ? $storyPreview : ''],
    ['n' => $waiting['members'], 'one' => 'manage.wait_member', 'many' => 'manage.wait_members', 'url' => '/queue?tab=members', 'cta' => 'manage.cta_approve', 'preview' => $waiting['members'] > 0 ? $memberPreview : ''],
    ['n' => $waiting['reports'], 'one' => 'manage.wait_report', 'many' => 'manage.wait_reports', 'url' => '/queue?tab=reports', 'cta' => 'manage.cta_look', 'preview' => $waiting['reports'] > 0 ? $reportPreview : ''],
    ['n' => $waiting['tags'], 'one' => 'manage.wait_tag', 'many' => 'manage.wait_tags', 'url' => '/tagqueue', 'cta' => 'manage.cta_sort', 'preview' => $waiting['tags'] > 0 ? $tagPreview : ''],
];
?>
<div class="home-head">
  <div>
    <h1><?= \App\Lang::t('manage.heading') ?></h1>
    <p class="lede"><?php if ($todo > 0): ?><?= \App\Lang::t($todo === 1 ? 'manage.lede_one' : 'manage.lede', ['n' => $todo, 'age' => $oldest !== null ? \App\Dates::span($oldest) : \App\Dates::span(null)]) ?><?php else: ?><?= \App\Lang::t('manage.now_clear') ?><?php endif; ?></p>
  </div>
  <p class="admin-quick"><?php if (\App\Features::on('news')): ?><a class="btn" href="/news/new"><?= \App\Lang::t('nav.post_news') ?></a> <?php endif; ?><a class="btn" href="/story/new"><?= \App\Lang::t('nav.post_story') ?></a></p>
</div>
<?php if ($stillOwner): ?>
<p class="admin-help"><?= $this->e(\App\Lang::t('manage.pick_penname')) ?> <a href="/account/settings#penname"><?= $this->e(\App\Lang::t('manage.pick_penname_link')) ?></a></p>
<?php endif; ?>
<div class="home-cols">
<section aria-labelledby="manage-now" class="home-now">
  <h2 id="manage-now"><?= \App\Lang::t('manage.now') ?></h2>
  <ul class="home-todo">
    <?php foreach ($rows as $row): if ($row['n'] < 1) continue; ?>
    <li class="home-row is-urgent"><span class="home-n"><?= (int) $row['n'] ?></span>
      <span class="home-what"><span class="home-label"><?= \App\Lang::t($row['n'] === 1 ? $row['one'] : $row['many']) ?></span>
        <span class="home-preview"><?= $this->e($row['preview']) ?></span></span>
      <a class="btn" href="<?= $this->e($row['url']) ?>"><?= \App\Lang::t($row['cta']) ?></a></li>
    <?php endforeach; ?>
    <?php if ($waiting['new'] > 0): ?>
    <li class="home-row is-calm"><span class="home-n"><?= (int) $waiting['new'] ?></span>
      <span class="home-what"><span class="home-label"><?= \App\Lang::t($waiting['new'] === 1 ? 'manage.new_member' : 'manage.new_members') ?></span>
        <?php if ($posted > 0): ?><span class="home-preview"><?= \App\Lang::t($posted === 1 ? 'manage.preview_new_posted_one' : 'manage.preview_new_posted', ['n' => $posted]) ?></span><?php endif; ?></span>
      <a class="home-link" href="/adminmembers"><?= \App\Lang::t('manage.cta_members') ?></a></li>
    <?php endif; ?>
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
      <span class="home-log-meta"><?= $this->e((string) $r['a'] !== '' ? $r['a'] : \App\Lang::t($r['n'] !== null ? 'adminmembers.no_penname' : 'activity.unknown')) ?>, <time datetime="<?= $this->e($r['d']) ?>"><?= $this->e(\App\Dates::ago((string) $r['d']) ?: \App\Dates::format((string) $r['d'], false, true)) ?></time></span></li>
    <?php endforeach; ?>
  </ul>
  <p><a href="/activity"><?= \App\Lang::t('manage.recent_all') ?></a></p>
  <?php endif; ?>
</section>
</div>
<h2 class="home-all"><?= \App\Lang::t('manage.all_screens') ?></h2>
<div class="home-screens">
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
</div>
