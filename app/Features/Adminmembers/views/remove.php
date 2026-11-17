<?php // app/views/adminmembers/remove.php ?>
<?php $this->layout('layout'); ?>
<section class="member-delete-panel">
  <h1><?= \App\Lang::t('adminmembers.remove_heading') ?></h1>
  <dl class="delete-facts">
    <div><dt><?= \App\Lang::t('adminmembers.column_member') ?></dt><dd><?= $this->e($member['penname'] ?? \App\Lang::t('adminmembers.no_penname')) ?></dd></div>
    <div><dt><?= \App\Lang::t('adminmembers.column_email') ?></dt><dd><?= $this->e($member['email']) ?></dd></div>
    <div><dt><?= \App\Lang::t('adminmembers.column_role') ?></dt><dd><?= \App\Lang::t('adminmembers.role.' . $member['role']) ?></dd></div>
    <div><dt><?= \App\Lang::t('adminmembers.delete_stories_fact') ?></dt><dd><?= number_format($storyCount) ?></dd></div>
  </dl>
  <p class="delete-warning"><?= \App\Lang::t('adminmembers.remove_intro') ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/adminmembers/destroy/<?= (int) $member['id'] ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <fieldset>
      <legend><?= \App\Lang::t('adminmembers.remove_stories_legend') ?></legend>
      <label class="delete-choice"><input type="radio" name="stories" value="move" checked> <?= \App\Lang::t('adminmembers.remove_move') ?></label>
      <label class="delete-heir"><?= \App\Lang::t('adminmembers.remove_move_to') ?> <input name="penname" maxlength="100" placeholder="<?= $this->e(\App\Lang::t('adminmembers.remove_penname_placeholder')) ?>"></label>
      <label class="delete-choice"><input type="radio" name="stories" value="remove"> <?= \App\Lang::t('adminmembers.remove_remove') ?></label>
    </fieldset>
    <p class="delete-actions"><button type="submit" class="btn-danger"><?= \App\Lang::t('adminmembers.remove_submit') ?></button> <a class="btn" href="/adminmembers"><?= \App\Lang::t('adminmembers.cancel') ?></a></p>
  </form>
</section>
