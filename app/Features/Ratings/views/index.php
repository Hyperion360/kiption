<?php // app/Features/Ratings/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('ratings.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('ratings.help') ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <ul>
    <?php foreach ($rows as $r): ?>
    <li>
      <strong><?= $this->e($r['label']) ?></strong>
      <?= (int) $r['is_adult'] === 1 ? ' ' . \App\Lang::t('ratings.adult_tag') : '' ?>
      <span class="chapter-meta"><?= \App\Lang::t('ratings.story_count', ['n' => (int) $r['story_count']]) ?></span>
      <form method="post" action="/ratings/move/<?= (int) $r['id'] ?>/up" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_up') ?></button>
      </form>
      <form method="post" action="/ratings/move/<?= (int) $r['id'] ?>/down" class="inline">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_down') ?></button>
      </form>
      <a href="/ratings/edit/<?= (int) $r['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
      <a href="/ratings/delete/<?= (int) $r['id'] ?>"><?= \App\Lang::t('common.delete') ?></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <h2><?= \App\Lang::t('ratings.add_heading') ?></h2>
  <form method="post" action="/ratings/create">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('ratings.label') ?> <input name="label" required maxlength="40"></label>
    <label class="inline"><input type="checkbox" name="is_adult" value="1"> <?= \App\Lang::t('ratings.adult_label') ?></label>
    <label><?= \App\Lang::t('ratings.warning') ?> <input name="warning_text" maxlength="300"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
</section>
