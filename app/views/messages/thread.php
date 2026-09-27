<?php // app/views/messages/thread.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('messages.with', ['name' => $this->e($rows[0]['partner_name'])]) ?></h1>
  <?php $count = 0; foreach ($rows as $m): if ($m['mid'] !== null) $count++; endforeach; ?>
  <?php if ($count === 0): ?><p class="chapter-meta"><?= \App\Lang::t('messages.empty_thread') ?></p><?php endif; ?>
  <ul>
    <?php foreach ($rows as $m): ?>
      <?php if ($m['mid'] === null) continue; // the anchor row of an empty thread ?>
      <li class="<?= (int) $m['sender_id'] === $me ? 'mine' : 'theirs' ?>">
        <span class="chapter-meta"><?= $this->e($m['sender_name']) ?>, <?= $this->e(substr((string) $m['created_at'], 0, 10)) ?></span>
        <div class="prose"><?= \App\Markdown::render((string) $m['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="/messages/send/<?= $this->e($slug) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('messages.body_label') ?> <textarea name="body" rows="6" maxlength="5000" required></textarea></label>
    <button type="submit"><?= \App\Lang::t('messages.reply') ?></button>
  </form>
</section>
