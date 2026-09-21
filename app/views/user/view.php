<?php // app/views/user/view.php ?>
<?php $this->layout('layout'); ?>
<link rel="alternate" type="application/atom+xml" title="<?= $this->e($profile['penname']) ?>" href="/feed/author/<?= $this->e($slug) ?>">
<section class="profile-card h-card">
  <?php if (!empty($profile['avatar_path'])): ?>
    <img class="avatar u-photo" src="<?= $this->e($profile['avatar_path']) ?>" alt="<?= $this->e(\App\Lang::t('user.avatar_of', ['name' => $profile['penname']])) ?>">
  <?php endif; ?>
  <h1 class="p-name"><?= $this->e($profile['penname']) ?></h1>
  <p class="chapter-meta">
    <?php if (in_array($profile['role'], ['validated_author', 'moderator', 'admin'], true)): ?><span class="badge"><?= \App\Lang::t('user.author_badge') ?></span><?php endif; ?>
    <?php if ((int) $profile['is_beta'] === 1): ?><span class="badge"><?= \App\Lang::t('user.beta_reader') ?></span><?php endif; ?>
    | <?= \App\Lang::t('user.member_since', ['date' => $this->e(substr((string) $profile['created_at'], 0, 10))]) ?>
    | <?= \App\Lang::t('common.works_count', ['n' => number_format((int) $profile['story_count'])]) ?>
    | <?= \App\Lang::t('user.series_count', ['n' => number_format((int) $profile['series_count'])]) ?>
  </p>
  <?php if (($profile['bio'] ?? '') !== ''): ?>
    <div class="prose p-note"><?= \App\Markdown::render((string) $profile['bio']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
  <?php endif; ?>
  <?php if (!empty($profile['support_url'])): ?>
    <p><a href="<?= $this->e($profile['support_url']) ?>" rel="noopener nofollow"><?= \App\Lang::t('user.support', ['name' => $this->e($profile['penname'])]) ?></a></p>
  <?php endif; ?>
  <nav class="tabs chapter-meta" aria-label="<?= $this->e(\App\Lang::t('user.tabs_aria')) ?>">
    <a href="/user/view/<?= $this->e($slug) ?>" class="u-url"><?= \App\Lang::t('user.profile_tab') ?></a> |
    <a href="/user/stories/<?= $this->e($slug) ?>"><?= \App\Lang::t('user.stories_tab') ?></a> |
    <a href="/user/favorites/<?= $this->e($slug) ?>"><?= \App\Lang::t('user.favorites_tab') ?></a>
    <?php if ($loggedIn && \App\Features::on('contact')): ?> |
    <a href="/user/contact/<?= $this->e($slug) ?>"><?= \App\Lang::t('user.contact_tab') ?></a>
    <?php endif; ?>
  </nav>
</section>
