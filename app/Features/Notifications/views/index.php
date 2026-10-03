<?php // app/views/notifications/index.php ?>
<?php $this->layout('layout'); ?>
<?php $unread = count(array_filter($rows, static fn (array $n): bool => $n['read_at'] === null)); ?>
<section class="page">
  <header class="page-head">
    <h1><?= \App\Lang::t('notifications.heading') ?></h1>
    <?php if ($unread > 0): /* the action exists only when there is something to mark */ ?>
    <div class="page-actions">
      <form method="post" action="/notifications/read" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button type="submit" class="link-btn"><?= \App\Lang::t('notifications.mark_read') ?></button>
      </form>
    </div>
    <?php endif; ?>
  </header>
  <?php if ($rows === []): ?><p class="empty-state"><?= \App\Lang::t('notifications.none') ?></p>
  <?php else: ?>
  <ul class="notif-list">
    <?php foreach ($rows as $n): ?>
      <?php
      // Story links ride the {story} param pre-built (escaped pieces, anchor
      // included); actors fall back to the generic stand-ins when anonymous.
      $storyLink = '<a href="/story/view/' . $this->e($n['story_slug'] ?? '') . '">';
      $story = $storyLink . $this->e($n['story_title'] ?? '');
      $story .= '</a>';
      $actor = fn (string $fallback): string => $this->e($n['actor'] ?? \App\Lang::t($fallback));
      ?>
      <li class="<?= $n['read_at'] === null ? 'unread' : '' ?>">
        <?php /* the accent square is visual only; screen readers hear the state */ ?>
        <?php if ($n['read_at'] === null): ?><span class="visually-hidden"><?= \App\Lang::t('notifications.unread') ?>: </span><?php endif; ?>
        <?php if ($n['kind'] === 'kudos' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.kudos', ['actor' => $actor('notifications.a_reader'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'favorite' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.favorite', ['actor' => $actor('notifications.a_reader'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'review' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.review', ['actor' => $actor('notifications.a_reader'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'reply' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.reply', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'follow'): ?>
          <span><?= \App\Lang::t('notifications.follow', ['actor' => $actor('notifications.someone')]) ?></span>
        <?php elseif ($n['kind'] === 'pm'): ?>
          <?php // The thread link rides the actor's profile slug (the actor_slug
                // scalar fold); the actor falls back to the stand-in when the
                // sender row is gone (the cascade deleted them). ?>
          <?php
          $pmLink = '<a href="/messages/view/' . $this->e($n['actor_slug'] ?? '') . '">';
          $pmLink .= $this->e($n['actor'] ?? \App\Lang::t('notifications.someone'));
          $pmLink .= '</a>';
          ?>
          <span><?= \App\Lang::t('notifications.pm', ['actor' => $pmLink]) ?></span>
        <?php elseif ($n['kind'] === 'update' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.update', ['story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'series_submit' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.series_submit', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'series_confirm' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.series_confirm', ['story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'challenge_submit' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.challenge_submit', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'challenge_confirm' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.challenge_confirm', ['story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'coauthor' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.coauthor', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php else: ?>
          <span><?= $this->e($n['kind']) ?></span>
        <?php endif; ?>
        <time class="notif-time" datetime="<?= $this->e($n['created_at']) ?>"><?= $this->e(date('M j', strtotime((string) $n['created_at']))) ?></time>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
