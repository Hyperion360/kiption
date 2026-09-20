<?php // app/views/notifications/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('notifications.heading') ?></h1>
  <form method="post" action="/notifications/read">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('notifications.mark_read') ?></button>
  </form>
  <?php if ($rows === []): ?><p class="chapter-meta"><?= \App\Lang::t('notifications.none') ?></p>
  <?php else: ?>
  <ul>
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
        <?php elseif ($n['kind'] === 'update' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.update', ['story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'series_submit' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.series_submit', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'series_confirm' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.series_confirm', ['story' => $story]) ?></span>
        <?php elseif ($n['kind'] === 'coauthor' && $n['story_id'] !== null): ?>
          <span><?= \App\Lang::t('notifications.coauthor', ['actor' => $actor('notifications.someone'), 'story' => $story]) ?></span>
        <?php else: ?>
          <span><?= $this->e($n['kind']) ?></span>
        <?php endif; ?>
        <span class="chapter-meta"><?= $this->e($n['created_at']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
