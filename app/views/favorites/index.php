<?php // app/views/favorites/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Your favorites</h1>
  <?php if ($rows === []): ?><p class="chapter-meta">Nothing favorited yet.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <a href="/story/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a>
        <span class="chapter-meta">(Kudos: <?= number_format((int) $r['kudos_count']) ?>, updated <?= $this->e($r['updated_at']) ?>)</span>
        <p><?= $this->e($r['summary']) ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
