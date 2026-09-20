<?php // app/views/user/contact.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Contact <?= $this->e($target['penname']) ?></h1>
  <?php if ($sent): ?>
    <p class="chapter-meta">Message sent to <?= $this->e($target['penname']) ?>.</p>
    <p><a href="/user/view/<?= $this->e($slug) ?>">Back to their profile</a></p>
  <?php else: ?>
    <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
    <p class="chapter-meta">Delivered by email through the site; the reply path points at your own contact form, so neither address is exposed here.</p>
    <form method="post" action="/user/contact/<?= $this->e($slug) ?>">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <label>Message (1 to 5000 characters) <textarea name="body" rows="8" maxlength="5000" required></textarea></label>
      <button type="submit">Send</button>
    </form>
  <?php endif; ?>
</section>
