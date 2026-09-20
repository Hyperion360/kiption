<?php // app/views/templates/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Edit mail template</h1>
  <p><strong><?= $this->e($name) ?></strong> <a href="/templates">Back to templates</a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/templates/update/<?= $this->e($name) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label>Subject <input name="subject" required value="<?= $this->e($subject) ?>"></label>
    <label>Body <textarea name="body" rows="8"><?= $this->e($body) ?></textarea></label>
    <button type="submit">Save</button>
  </form>
  <?php if ($vocabulary !== []): ?>
  <h2>Placeholders</h2>
  <p class="chapter-meta">Placeholders in braces are filled at send time; anything else sends literally.</p>
  <ul>
    <?php foreach ($vocabulary as $placeholder => $meaning): ?>
      <li><code><?= $this->e($placeholder) ?></code> <?= $this->e($meaning) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <p class="chapter-meta">This template has no placeholders.</p>
  <?php endif; ?>
</section>
