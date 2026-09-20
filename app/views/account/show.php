<?php // app/views/account/show.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Your account</h1>
  <p class="chapter-meta"><?= $this->e($me['a']) ?> (<?= $this->e($me['c']) ?>) - <?= $this->e($me['b']) ?></p>
  <?php if (!empty($me['d'])): ?>
    <img class="avatar" src="<?= $this->e($me['d']) ?>" alt="Your avatar" width="80" height="80">
  <?php endif; ?>
  <form method="post" action="/account/avatar" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Avatar (PNG/JPG/WEBP/GIF, max 2 MiB) <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <button type="submit">Upload</button>
  </form>
  <form method="post" action="/account/support">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Support link (http(s) only, shown on your stories) <input name="support_url" maxlength="200" value="<?= $this->e($me['e'] ?? '') ?>"></label>
    <button type="submit">Save support link</button>
  </form>
  <form method="post" action="/account/prefs">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Bio (markdown, shown on your profile) <textarea name="bio" maxlength="2000" rows="4"><?= $this->e($me['g'] ?? '') ?></textarea></label>
    <label><input type="checkbox" name="is_beta" value="1"<?= (int) ($me['h'] ?? 0) === 1 ? ' checked' : '' ?>> Show the Beta reader badge on my profile</label>
    <fieldset>
      <legend>Default listing sort</legend>
      <label><input type="radio" name="default_sort" value="recent"<?= ($me['i'] ?? 'recent') !== 'alpha' ? ' checked' : '' ?>> Recently updated</label>
      <label><input type="radio" name="default_sort" value="alpha"<?= ($me['i'] ?? '') === 'alpha' ? ' checked' : '' ?>> Alphabetical by title</label>
    </fieldset>
    <?php // toc stays cookie-driven: the cookie is the value read() actually consumes ?>
    <label><input type="checkbox" name="toc_first" value="1"<?= !empty($tocOn) ? ' checked' : '' ?>> Open the table of contents first when I start a story</label>
    <label><input type="checkbox" name="notify_review" value="1"<?= (int) ($me['j'] ?? 1) === 1 ? ' checked' : '' ?>> Notify me about reviews on my works</label>
    <label><input type="checkbox" name="notify_response" value="1"<?= (int) ($me['l'] ?? 1) === 1 ? ' checked' : '' ?>> Notify me about replies to my reviews</label>
    <label><input type="checkbox" name="notify_favorites" value="1"<?= (int) ($me['m'] ?? 1) === 1 ? ' checked' : '' ?>> Notify me when someone favorites my works</label>
    <label><input type="checkbox" name="notify_favorite_digest" value="1"<?= (int) ($me['f'] ?? 0) === 1 ? ' checked' : '' ?>> Batch updates to my favorited stories into the email digest instead of immediate mail</label>
    <button type="submit">Save preferences</button>
  </form>
  <h2>Your stories</h2>
  <?php if ($stories === []): ?><p class="chapter-meta">None yet. <a href="/story/new">Start one</a>.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($stories as $s): ?>
      <li>
        <a href="/story/edit/<?= $this->e($s['a']) ?>"><?= $this->e($s['b']) ?></a>
        <span class="chapter-meta">(<?= $s['c'] === '1' ? 'published' : 'awaiting validation' ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2>Your series</h2>
  <?php if ($seriesList === []): ?><p class="chapter-meta">None yet.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($seriesList as $s): ?>
      <li>
        <a href="/series/view/<?= $this->e($s['a']) ?>"><?= $this->e($s['b']) ?></a>
        <span class="chapter-meta">(<?= (int) $s['c'] ?> works<?= (int) $s['d'] > 0 ? ', ' . (int) $s['d'] . ' pending' : '' ?>, <?= $this->e($s['e']) ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <p><a href="/series/new">New series</a></p>
  <h2>Authors you follow</h2>
  <?php if ($following === []): ?><p class="chapter-meta">Nobody yet.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($following as $f): ?>
      <li>
        <strong><?= $this->e($f['b']) ?></strong>
        <span class="chapter-meta">(<?= (int) $f['d'] ?> stories, notify mode: <?= $this->e($f['c']) ?>)</span>
        <form method="post" action="/follow/mode/<?= (int) $f['a'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Cycle mode</button>
        </form>
        <form method="post" action="/follow/stop/<?= (int) $f['a'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Unfollow</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2>Continue reading</h2>
  <?php if ($progress === []): ?><p class="chapter-meta">Nothing in progress.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($progress as $p): ?>
      <li>
        <a href="/story/read/<?= $this->e($p['a']) ?>/<?= (int) $p['c'] ?>"><?= $this->e($p['b']) ?></a>
        <span class="chapter-meta">(chapter <?= (int) $p['c'] ?> of <?= (int) $p['d'] ?>)</span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2>Marked for later</h2>
  <?php if ($marked === []): ?><p class="chapter-meta">Nothing marked.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($marked as $m): ?>
      <li><a href="/story/view/<?= $this->e($m['a']) ?>"><?= $this->e($m['b']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
