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
</section>
