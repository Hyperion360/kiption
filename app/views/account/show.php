<?php // app/views/account/show.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('account.heading') ?></h1>
  <p class="chapter-meta"><?= $this->e($me['a']) ?> (<?= $this->e($me['c']) ?>) - <?= $this->e($me['b']) ?></p>
  <?php if (!empty($me['d'])): ?>
    <img class="avatar" src="<?= $this->e($me['d']) ?>" alt="<?= $this->e(\App\Lang::t('account.avatar_alt')) ?>" width="80" height="80">
  <?php endif; ?>
  <form method="post" action="/account/avatar" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.avatar_label') ?> <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <button type="submit"><?= \App\Lang::t('account.upload') ?></button>
  </form>
  <form method="post" action="/account/support">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.support_label') ?> <input name="support_url" maxlength="200" value="<?= $this->e($me['e'] ?? '') ?>"></label>
    <button type="submit"><?= \App\Lang::t('account.support_save') ?></button>
  </form>
  <form method="post" action="/account/prefs">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('account.bio_label') ?> <textarea name="bio" maxlength="2000" rows="4"><?= $this->e($me['g'] ?? '') ?></textarea></label>
    <label><input type="checkbox" name="is_beta" value="1"<?= (int) ($me['h'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.beta_pref') ?></label>
    <fieldset>
      <legend><?= \App\Lang::t('account.sort_legend') ?></legend>
      <label><input type="radio" name="default_sort" value="recent"<?= ($me['i'] ?? 'recent') !== 'alpha' ? ' checked' : '' ?>> <?= \App\Lang::t('account.sort_recent') ?></label>
      <label><input type="radio" name="default_sort" value="alpha"<?= ($me['i'] ?? '') === 'alpha' ? ' checked' : '' ?>> <?= \App\Lang::t('account.sort_alpha') ?></label>
    </fieldset>
    <?php // toc stays cookie-driven: the cookie is the value read() actually consumes ?>
    <label><input type="checkbox" name="toc_first" value="1"<?= !empty($tocOn) ? ' checked' : '' ?>> <?= \App\Lang::t('account.toc_first') ?></label>
    <label><input type="checkbox" name="notify_review" value="1"<?= (int) ($me['j'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_review') ?></label>
    <label><input type="checkbox" name="notify_response" value="1"<?= (int) ($me['l'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_response') ?></label>
    <label><input type="checkbox" name="notify_favorites" value="1"<?= (int) ($me['m'] ?? 1) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_favorites') ?></label>
    <label><input type="checkbox" name="notify_favorite_digest" value="1"<?= (int) ($me['f'] ?? 0) === 1 ? ' checked' : '' ?>> <?= \App\Lang::t('account.notify_digest') ?></label>
    <button type="submit"><?= \App\Lang::t('account.save_prefs') ?></button>
  </form>
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
  <?php if (\App\Features::on('stats')): ?><p><a href="/stats"><?= \App\Lang::t('stats.link') ?></a></p><?php endif; ?>
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
</section>
