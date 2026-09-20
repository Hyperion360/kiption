<?php // app/views/user/view.php ?>
<?php $this->layout('layout'); ?>
<link rel="alternate" type="application/atom+xml" title="<?= $this->e($profile['penname']) ?>" href="/feed/author/<?= $this->e($slug) ?>">
<section class="profile-card">
  <?php if (!empty($profile['avatar_path'])): ?>
    <img class="avatar" src="<?= $this->e($profile['avatar_path']) ?>" alt="Avatar of <?= $this->e($profile['penname']) ?>">
  <?php endif; ?>
  <h1><?= $this->e($profile['penname']) ?></h1>
  <p class="chapter-meta">
    <?php if (in_array($profile['role'], ['validated_author', 'moderator', 'admin'], true)): ?><span class="badge">Author</span><?php endif; ?>
    <?php if ((int) $profile['is_beta'] === 1): ?><span class="badge">Beta reader</span><?php endif; ?>
    | Member since <?= $this->e(substr((string) $profile['created_at'], 0, 10)) ?>
    | <?= number_format((int) $profile['story_count']) ?> works
    | <?= number_format((int) $profile['series_count']) ?> series
  </p>
  <?php if (($profile['bio'] ?? '') !== ''): ?>
    <div class="prose"><?= \App\Markdown::render((string) $profile['bio']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <?php endif; ?>
  <?php if (!empty($profile['support_url'])): ?>
    <p><a href="<?= $this->e($profile['support_url']) ?>" rel="noopener nofollow">Support <?= $this->e($profile['penname']) ?></a></p>
  <?php endif; ?>
  <nav class="tabs chapter-meta" aria-label="Profile">
    <span>Profile</span> |
    <a href="/user/stories/<?= $this->e($slug) ?>">Stories</a> |
    <a href="/user/favorites/<?= $this->e($slug) ?>">Favorites</a>
    <?php if ($loggedIn): ?> |
    <a href="/user/contact/<?= $this->e($slug) ?>">Contact</a>
    <?php endif; ?>
  </nav>
</section>
