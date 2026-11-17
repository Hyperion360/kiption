<?php // app/views/site_foot.php
/* The site footer: a brand line and four link columns (Read, Write, the
   archive's own name, Legal), then the legal line and credit. Built only from
   destinations that exist and are switched on, and identical for every
   visitor, so cached pages stay stable. Reading surfaces hide it (reader.css).
   Takes navOn, navFile. */
$footSeen = ['/' => 1, '/browse' => 1, '/browse/recent' => 1, '/browse/authors' => 1, '/series' => 1, '/search' => 1,
             '/account' => 1, '/auth/login' => 1, '/news' => 1, '/top' => 1, '/lists' => 1, '/challenges' => 1, '/feed' => 1,
             '/feed/subscribe' => 1, '/story/new' => 1, '/help' => 1, '/rules' => 1, '/terms' => 1, '/contact' => 1];
$notices = \App\Notices::footerLinks();
$legalKeys = ['/rules', '/terms'];
?>
  <footer class="site-foot ft2">
    <p class="foot-brand"><a href="/"><?= $this->e(\App\SiteName::get()) ?></a> <span><?= \App\Lang::t('foot.blurb') ?> <?= \App\Lang::t('foot.kip') ?>.</span></p>
    <nav class="foot-nav" aria-label="<?= $this->e(\App\Lang::t('foot.more_label')) ?>">
      <div class="foot-col">
        <h2 class="foot-label"><?= \App\Lang::t('nav.read') ?></h2>
        <a href="/browse"><?= \App\Lang::t('nav.browse') ?></a>
        <?php if ($navOn['/browse/recent']): ?><a href="/browse/recent"><?= \App\Lang::t('nav.way_recent') ?></a><?php endif; ?>
        <?php if ($navOn['/browse/authors']): ?><a href="/browse/authors"><?= \App\Lang::t('nav.authors') ?></a><?php endif; ?>
        <?php if ($navOn['/series'] && \App\Settings::on('series_on')): ?><a href="/series"><?= \App\Lang::t('nav.series') ?></a><?php endif; ?>
        <?php if (\App\Features::on('toplists')): ?><a href="/top"><?= \App\Lang::t('top.heading') ?></a><?php endif; ?>
        <?php if (\App\Features::on('lists')): ?><a href="/lists"><?= \App\Lang::t('foot.lists') ?></a><?php endif; ?>
        <?php if (\App\Features::on('challenges')): ?><a href="/challenges"><?= \App\Lang::t('challenges.index') ?></a><?php endif; ?>
        <?php if (\App\Features::on('news')): ?><a href="/news"><?= \App\Lang::t('news.heading') ?></a><?php endif; ?>
      </div>
<?php if (\App\Posting::guestOffered()): ?>
      <div class="foot-col">
        <h2 class="foot-label"><?= \App\Lang::t('nav.write') ?></h2>
        <a href="/story/new"><?= \App\Lang::t('nav.post_story') ?></a>
      </div>
<?php endif; ?>
      <div class="foot-col">
        <h2 class="foot-label"><?= $this->e(\App\SiteName::get()) ?></h2>
<?php foreach ($notices as $noticeUrl => $noticeLabel): if (in_array($noticeUrl, $legalKeys, true)) continue; ?>
        <a href="<?= $noticeUrl ?>"><?= \App\Lang::t($noticeLabel) ?></a>
<?php endforeach; ?>
<?php foreach (\App\NavLinks::all($navFile ?? '') as $l): if (isset($footSeen[$l['url']])) continue; ?>
        <a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a>
<?php endforeach; ?>
        <?php if (\App\Features::on('feeds')): ?><a href="/feed/subscribe"><?= \App\Lang::t('common.feed_title') ?></a><?php endif; ?>
      </div>
<?php $legal = array_intersect_key($notices, array_flip($legalKeys)); if ($legal !== []): ?>
      <div class="foot-col">
        <h2 class="foot-label"><?= \App\Lang::t('foot.legal') ?></h2>
<?php foreach ($legal as $noticeUrl => $noticeLabel): ?>
        <a href="<?= $noticeUrl ?>"><?= \App\Lang::t($noticeLabel) ?></a>
<?php endforeach; ?>
      </div>
<?php endif; ?>
    </nav>
    <?php /* The operator's legal line (the Attribution seam's sibling):
       copyright holder and legal links from the boot config, absent when
       unconfigured. The year is baked at render time. Only https and
       site-relative urls link; anything else renders as plain text. */ ?>
    <?php if (\App\FooterLegal::has()): ?>
    <p class="foot-legal"><?php foreach (\App\FooterLegal::links() as $l): ?><?php if (\App\FooterLegal::linkable($l['url'])): ?><a href="<?= $this->e($l['url']) ?>"><?= $this->e($l['label']) ?></a><?php else: ?><?= $this->e($l['label']) ?><?php endif; ?> <?php endforeach; ?><?php if (\App\FooterLegal::copyright() !== ''): ?><span>© <?= date('Y') ?> <?php if (\App\FooterLegal::linkable(\App\FooterLegal::copyrightUrl())): ?><a href="<?= $this->e(\App\FooterLegal::copyrightUrl()) ?>" rel="noopener"><?= $this->e(\App\FooterLegal::copyright()) ?></a><?php else: ?><?= $this->e(\App\FooterLegal::copyright()) ?><?php endif; ?></span><?php endif; ?></p>
    <?php endif; ?>
    <?php /* Attribution (the WordPress pattern): on by default, off by the
       powered_by config key, identical bytes for every visitor. */ ?>
    <?php if (\App\Attribution::on()): ?>
    <p class="poweredby"><a href="<?= $this->e(\App\Attribution::url()) ?>"><?= \App\Lang::t('foot.powered_by') ?></a></p>
    <?php endif; ?>
  </footer>
