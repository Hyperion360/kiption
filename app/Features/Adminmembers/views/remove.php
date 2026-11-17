<?php // app/views/adminmembers/remove.php ?>
<?php $this->layout('layout'); ?>
<?php
$name = (string) ($member['penname'] ?? '') !== '' ? (string) $member['penname'] : \App\Lang::t('adminmembers.no_penname');
$count = fn (int $n, string $key): string => \App\Lang::t($n === 1 ? $key . '_one' : $key, ['n' => number_format($n)]);
$stories = (int) $member['stories'];
?>
<section class="member-delete-panel">
  <p class="delete-eyebrow"><?= \App\Lang::t('adminmembers.remove_heading') ?></p>
  <h1><?= \App\Lang::t('adminmembers.remove_title', ['name' => $this->e($name)]) ?></h1>
  <p class="delete-warning"><?= \App\Lang::t('adminmembers.remove_intro') ?></p>
  <p class="delete-meta"><?= $this->e($member['email']) ?> · <?= $count($stories, 'adminmembers.stories') ?> · <?= $count((int) $member['open_reports'], 'adminmembers.open_reports') ?> · <?= \App\Lang::t('adminmembers.since', ['date' => $this->e(\App\Dates::format($member['created_at']))]) ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/adminmembers/destroy/<?= (int) $member['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <fieldset class="delete-choices">
      <legend><?= \App\Lang::t('adminmembers.remove_legend_n', ['stories' => $count($stories, 'adminmembers.stories')]) ?></legend>
      <div class="delete-card" data-js-module="heir-lookup" data-heir-url="/adminmembers/heir?for=<?= (int) $member['id'] ?>">
        <label class="delete-choice"><input type="radio" name="stories" value="move" checked> <?= \App\Lang::t('adminmembers.remove_move') ?></label>
        <label class="delete-heir" for="delete-heir"><?= \App\Lang::t('adminmembers.remove_move_to') ?></label>
        <input id="delete-heir" name="penname" maxlength="100" autocomplete="off">
        <span class="delete-found" data-heir-status aria-live="polite"></span>
        <span class="delete-note"><?= \App\Lang::t('adminmembers.remove_move_note') ?></span>
      </div>
      <div class="delete-card">
        <label class="delete-choice"><input type="radio" name="stories" value="remove"> <?= \App\Lang::t('adminmembers.remove_remove') ?></label>
        <span class="delete-note"><?= \App\Lang::t('adminmembers.remove_leaves', ['stories' => $count($stories, 'adminmembers.stories'), 'chapters' => $count((int) $member['chapters'], 'adminmembers.chapters')]) ?></span>
      </div>
    </fieldset>
    <p class="delete-actions"><a class="btn" href="/adminmembers"><?= \App\Lang::t('adminmembers.cancel') ?></a> <button type="submit" class="btn-danger"><?= \App\Lang::t('adminmembers.remove_submit', ['name' => $this->e($name)]) ?></button></p>
  </form>
</section>
