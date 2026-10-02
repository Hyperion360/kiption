<?php // app/views/browse/authors.php ?>
<?php // The authors directory (undesigned in the comps): page head, an A-Z
     // letter row (square chips, the current letter filled), the members
     // table in the Kip admin style, and the shared pager. ?>
<?php $this->layout('layout'); ?>
<div class="page">
  <header class="page-head">
    <h1><?= $this->e($title) ?></h1>
    <?php if ($beta): ?><p class="lede"><?= \App\Lang::t('browse.authors_beta_only') ?></p><?php endif; ?>
  </header>
  <nav class="letter-nav" aria-label="<?= $this->e(\App\Lang::t('browse.authors_aria')) ?>">
    <a href="/browse/authors<?= $beta ? '?beta=1' : '' ?>"<?= $letter === '' ? ' aria-current="page"' : '' ?>><?= \App\Lang::t('browse.all') ?></a>
    <?php foreach (range('a', 'z') as $l): ?>
    <a href="/browse/authors/<?= $l ?><?= $beta ? '?beta=1' : '' ?>"<?= $letter === $l ? ' aria-current="page"' : '' ?>><?= strtoupper($l) ?></a>
    <?php endforeach; ?>
    <a href="/browse/authors/0<?= $beta ? '?beta=1' : '' ?>"<?= $letter === '0' ? ' aria-current="page"' : '' ?>>#</a>
  </nav>
  <table>
    <thead><tr><th scope="col"><?= \App\Lang::t('browse.column_member') ?></th><th scope="col" class="num"><?= \App\Lang::t('browse.column_works') ?></th><th scope="col"><?= \App\Lang::t('browse.column_joined') ?></th></tr></thead>
    <tbody>
<?php foreach ($members as $m): ?>
      <tr>
        <td>
          <a class="cell-strong" href="/user/view/<?= $this->e($m['profile_slug']) ?>"><?= $this->e($m['penname']) ?></a>
          <?php if ((int) $m['is_beta'] === 1): ?><span class="badge"><?= \App\Lang::t('browse.beta_reader') ?></span><?php endif; ?>
          <?php if ($loggedIn && \App\Features::on('mute') && (int) $m['id'] !== $me): ?>
          <form method="post" action="/mute/add/<?= $this->e($m['profile_slug']) ?>" class="inline">
            <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
            <button type="submit" class="link-btn quiet" aria-label="<?= $this->e(\App\Lang::t('user.mute_button_aria', ['name' => $m['penname']])) ?>"><?= \App\Lang::t('mute.button') ?></button>
          </form>
          <?php endif; ?>
        </td>
        <td class="num"><?= number_format((int) $m['story_count']) ?></td>
        <td class="mono"><?= $this->e(substr((string) $m['created_at'], 0, 10)) ?></td>
      </tr>
<?php endforeach; ?>
<?php if ($members === []): ?>
      <tr><td colspan="3" class="meta"><?= \App\Lang::t('browse.no_members') ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
<?php if ($page > 1 || !empty($hasOlder)): ?>
  <nav class="pager" aria-label="<?= $this->e(\App\Lang::t('common.pages_aria')) ?>">
  <?php if ($page > 1): ?><a href="<?= $this->e($baseUrl) ?><?= $beta ? '?beta=1&amp;page=' : '?page=' ?><?= $page - 1 ?>" rel="prev"><?= \App\Lang::t('common.previous') ?></a><?php endif; ?>
  <?php if (!empty($hasOlder)): ?><a class="pager-next" href="<?= $this->e($baseUrl) ?><?= $beta ? '?beta=1&amp;page=' : '?page=' ?><?= $page + 1 ?>" rel="next"><?= \App\Lang::t('common.next') ?></a><?php endif; ?>
  </nav>
<?php endif; ?>
</div>
