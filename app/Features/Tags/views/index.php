<?php // app/Features/Tags/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('tagsadmin.help') ?></p>
  <p><a href="/wrangling"><?= \App\Lang::t('tagsadmin.wrangling_link') ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php foreach ($types as $type): ?>
  <h2><?= $this->e($type['name']) ?></h2>
  <p>
    <form method="post" action="/tags/movetype/<?= (int) $type['id'] ?>/up" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_up') ?></button>
    </form>
    <form method="post" action="/tags/movetype/<?= (int) $type['id'] ?>/down" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_down') ?></button>
    </form>
    <a href="/tags/edittype/<?= (int) $type['id'] ?>"><?= \App\Lang::t('tagsadmin.rename_type') ?></a>
    <a href="/tags/deletetype/<?= (int) $type['id'] ?>"><?= \App\Lang::t('tagsadmin.delete_type') ?></a>
  </p>
  <?php if (empty($byType[(int) $type['id']])): ?><p class="chapter-meta"><?= \App\Lang::t('tagsadmin.no_tags') ?></p><?php else: ?>
  <ul>
    <?php foreach ($byType[(int) $type['id']] as $t): ?>
    <li>
      <?= $this->e($t['name']) ?>
      <?php if ($t['canonical_name'] !== null): ?><span class="chapter-meta"><?= \App\Lang::t('tagsadmin.merged_into') ?> <?= $this->e($t['canonical_name']) ?></span><?php endif; ?>
      <span class="chapter-meta"><?= \App\Lang::t('tagsadmin.story_count', ['n' => (int) $t['story_count']]) ?></span>
      <a href="/tags/edit/<?= (int) $t['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
      <a href="/tags/delete/<?= (int) $t['id'] ?>"><?= \App\Lang::t('common.delete') ?></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php endforeach; ?>
  <h2><?= \App\Lang::t('tagsadmin.add_tag_heading') ?></h2>
  <form method="post" action="/tags/addtag">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('tagsadmin.type') ?>
      <select name="tag_type_id" required>
        <?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"><?= $this->e($type['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
  <h2><?= \App\Lang::t('tagsadmin.add_type_heading') ?></h2>
  <form method="post" action="/tags/addtype">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
</section>
