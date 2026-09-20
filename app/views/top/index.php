<?php // app/views/top/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Top lists</h1>
<?php
// Four sections in a fixed order. Rows arrive rank-ordered per section (the
// fold's ORDER BY k, rank); rank renumbers gated survivors contiguously, so
// the <ol> numbering IS the displayed rank. Empty sections render their
// honest empty line; Top rated's floor of 3 root ratings makes "Not enough
// ratings yet" its only empty state.
?>
<section>
  <h2>Most favorited</h2>
<?php if ($sections['favorites'] === []): ?>
  <p class="meta">No favorites yet.</p>
<?php else: ?>
  <ol>
<?php foreach ($sections['favorites'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      by <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= (int) $row['count'] ?> favorites</span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2>Most kudos</h2>
<?php if ($sections['kudos'] === []): ?>
  <p class="meta">No kudos yet.</p>
<?php else: ?>
  <ol>
<?php foreach ($sections['kudos'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      by <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= (int) $row['count'] ?> kudos</span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2>Most reviewed</h2>
<?php if ($sections['reviews'] === []): ?>
  <p class="meta">No reviews yet.</p>
<?php else: ?>
  <ol>
<?php foreach ($sections['reviews'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      by <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= (int) $row['count'] ?> reviews</span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>

<section>
  <h2>Top rated</h2>
<?php if ($sections['rated'] === []): ?>
  <p class="meta">Not enough ratings yet</p>
<?php else: ?>
  <ol>
<?php foreach ($sections['rated'] as $row): ?>
    <li><a href="/story/view/<?= $this->e($row['slug']) ?>"><?= $this->e($row['title']) ?></a>
      by <a href="/user/view/<?= $this->e($row['profile_slug']) ?>"><?= $this->e($row['penname']) ?></a>
      <span class="meta"><?= $this->e($row['average']) ?> average from <?= (int) $row['ratings'] ?> ratings</span></li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</section>
