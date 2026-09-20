<?php // app/views/templates/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Mail templates</h1>
  <p class="chapter-meta">Every mail falls back to its built-in default until you save an edit here.</p>
  <ul>
    <?php foreach ($rows as $r): ?>
      <li>
        <strong><?= $this->e($r['name']) ?></strong>
        <span class="chapter-meta"><?= $this->e($r['subject']) ?></span>
        <a href="/templates/edit/<?= $this->e($r['name']) ?>">Edit</a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
