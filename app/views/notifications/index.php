<?php // app/views/notifications/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Notifications</h1>
  <form method="post" action="/notifications/read">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit">Mark all read</button>
  </form>
  <?php if ($rows === []): ?><p class="chapter-meta">Nothing yet.</p>
  <?php else: ?>
  <ul>
    <?php foreach ($rows as $n): ?>
      <li class="<?= $n['read_at'] === null ? 'unread' : '' ?>">
        <?php if ($n['kind'] === 'kudos' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'A reader') ?> left kudos on <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php elseif ($n['kind'] === 'favorite' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'A reader') ?> favorited <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php elseif ($n['kind'] === 'review' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'A reader') ?> reviewed <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php elseif ($n['kind'] === 'reply' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'Someone') ?> replied to a review on <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php elseif ($n['kind'] === 'follow'): ?>
          <span><?= $this->e($n['actor'] ?? 'Someone') ?> followed you</span>
        <?php elseif ($n['kind'] === 'update' && $n['story_id'] !== null): ?>
          <span>New chapter in <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php elseif ($n['kind'] === 'series_submit' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'Someone') ?> submitted <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a> to your series</span>
        <?php elseif ($n['kind'] === 'series_confirm' && $n['story_id'] !== null): ?>
          <span>Your story <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a> was added to a series</span>
        <?php elseif ($n['kind'] === 'coauthor' && $n['story_id'] !== null): ?>
          <span><?= $this->e($n['actor'] ?? 'Someone') ?> added you as coauthor on <a href="/story/view/<?= $this->e($n['story_slug']) ?>"><?= $this->e($n['story_title']) ?></a></span>
        <?php else: ?>
          <span><?= $this->e($n['kind']) ?></span>
        <?php endif; ?>
        <span class="chapter-meta"><?= $this->e($n['created_at']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
