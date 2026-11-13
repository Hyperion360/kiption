<?php // app/views/adminmembers/remove.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('adminmembers.remove_heading') ?></h1>
  <p><strong><?= $this->e($member['penname'] ?? \App\Lang::t('adminmembers.no_penname')) ?></strong> (<?= $this->e($member['email']) ?>)</p>
  <p><?= \App\Lang::t('adminmembers.remove_intro') ?></p>
  <p class="chapter-meta"><?= \App\Lang::t('adminmembers.remove_story_count', ['n' => number_format($storyCount)]) ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/adminmembers/destroy/<?= (int) $member['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <fieldset>
      <legend><?= \App\Lang::t('adminmembers.remove_stories_legend') ?></legend>
      <label><input type="radio" name="stories" value="move" checked> <?= \App\Lang::t('adminmembers.remove_move') ?></label>
      <label><?= \App\Lang::t('adminmembers.remove_move_to') ?> <input name="penname" maxlength="100" placeholder="<?= $this->e(\App\Lang::t('adminmembers.remove_penname_placeholder')) ?>"></label>
      <label><input type="radio" name="stories" value="remove"> <?= \App\Lang::t('adminmembers.remove_remove') ?></label>
    </fieldset>
    <button type="submit"><?= \App\Lang::t('adminmembers.remove_submit') ?></button>
  </form>
  <p><a href="/adminmembers"><?= \App\Lang::t('adminmembers.back') ?></a></p>
</section>
