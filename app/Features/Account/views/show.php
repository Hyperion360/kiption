<?php // app/views/account/show.php ?>
<?php $this->layout('layout'); ?>
<?php // The member's Library (the header's right-edge link, comp T2/D3): the
     // reading shelf leads, then the member's own work and follows. Profile
     // and preference forms live on /account/settings, linked from the head. ?>
<section class="page">
  <header class="page-head">
    <h1><?= \App\Lang::t('account.library_heading') ?></h1>
    <p class="lede"><?= $this->e($me['a']) ?> · <?= $this->e($me['b']) ?></p>
    <p class="page-actions"><a href="/account/settings"><?= \App\Lang::t('account.settings') ?></a><?php if (\App\Features::on('stats')): ?><a href="/stats"><?= \App\Lang::t('stats.link') ?></a><?php endif; ?></p>
  </header>
  <h2><?= \App\Lang::t('account.continue_reading') ?></h2>
  <?php if ($progress === []): ?><p class="chapter-meta"><?= \App\Lang::t('account.nothing_progress') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($progress as $p): ?>
      <li>
        <a href="/story/read/<?= $this->e($p['a']) ?>/<?= (int) $p['c'] ?>"><?= $this->e($p['b']) ?></a>
        <span class="chapter-meta">(<?= \App\Lang::t('account.chapter_of', ['n' => (int) $p['c'], 'm' => (int) $p['d']]) ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('account.marked') ?></h2>
  <?php if ($marked === []): ?><p class="chapter-meta"><?= \App\Lang::t('account.nothing_marked') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($marked as $m): ?>
      <li><a href="/story/view/<?= $this->e($m['a']) ?>"><?= $this->e($m['b']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('account.your_stories') ?></h2>
  <?php if ($stories === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?> <a href="/story/new"><?= \App\Lang::t('account.start_one') ?></a>.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($stories as $s): ?>
      <li>
        <a href="/story/edit/<?= $this->e($s['a']) ?>"><?= $this->e($s['b']) ?></a>
        <span class="chapter-meta">(<?= $s['c'] === '1' ? \App\Lang::t('account.published') : \App\Lang::t('story.awaiting_validation') ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('account.your_series') ?></h2>
  <?php if ($seriesList === []): ?><p class="chapter-meta"><?= \App\Lang::t('common.none_yet') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($seriesList as $s): ?>
      <li>
        <a href="/series/view/<?= $this->e($s['a']) ?>"><?= $this->e($s['b']) ?></a>
        <span class="chapter-meta">(<?= \App\Lang::t('common.works_count', ['n' => (int) $s['c']]) ?><?= (int) $s['d'] > 0 ? ', ' . \App\Lang::t('account.pending', ['n' => (int) $s['d']]) : '' ?>, <?= $this->e($s['e']) ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p><a href="/series/new"><?= \App\Lang::t('account.new_series') ?></a></p>
  <h2><?= \App\Lang::t('account.following') ?></h2>
  <?php if ($following === []): ?><p class="chapter-meta"><?= \App\Lang::t('account.nobody_yet') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($following as $f): ?>
      <li>
        <strong><?= $this->e($f['b']) ?></strong>
        <span class="chapter-meta">(<?= \App\Lang::t('account.stories_notify', ['n' => (int) $f['d'], 'mode' => $this->e($f['c'])]) ?>)</span>
        <form method="post" action="/follow/mode/<?= (int) $f['a'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('account.cycle_mode') ?></button>
        </form>
        <form method="post" action="/follow/stop/<?= (int) $f['a'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('account.unfollow') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
