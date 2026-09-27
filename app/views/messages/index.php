<?php // app/views/messages/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('messages.heading') ?></h1>
  <?php if ($rows === []): ?><p class="chapter-meta"><?= \App\Lang::t('messages.none') ?></p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $row): ?>
      <?php // One row per conversation: the partner link, the thread's latest
            // message as a preview, and the viewer's incoming unread badge. ?>
      <li class="<?= (int) $row['unread'] > 0 ? 'unread' : '' ?>">
        <a href="/messages/view/<?= $this->e($row['partner_slug']) ?>"><?= $this->e($row['partner_name']) ?></a>
        <span class="chapter-meta"><?= $this->e(mb_strimwidth((string) $row['body'], 0, 140, '...')) ?></span>
        <?php if ((int) $row['unread'] > 0): ?>
        <span class="chapter-meta"><?= \App\Lang::t('messages.unread_badge', ['n' => (int) $row['unread']]) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
