<?php // app/Features/Tags/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('tagsadmin.heading') ?></h1>
  <p class="admin-help"><?= \App\Lang::t('tagsadmin.help') ?></p>
  <?php /* Right under the help text that names these types, not below every
     tag list: on a phone the lists run several screens. */ ?>
  <?php if ($missingTypes !== []): ?>
  <section class="admin-suggest" aria-labelledby="suggest-h">
    <h2 id="suggest-h" class="section-label"><?= \App\Lang::t('tagsadmin.suggest_heading') ?></h2>
    <?php foreach ($missingTypes as $key): ?>
    <form method="post" action="/tags/addtype" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <input type="hidden" name="name" value="<?= $this->e(\App\Lang::t('tagsadmin.suggest_' . $key)) ?>">
      <button type="submit" class="<?= $types === [] ? 'btn' : 'btn-quiet' ?>"><?= \App\Lang::t('tagsadmin.suggest_add', ['type' => \App\Lang::t('tagsadmin.suggest_' . $key)]) ?></button>
    </form>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <p><a class="btn" href="/wrangling"><?= \App\Lang::t('tagsadmin.wrangling_link') ?></a></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php
  // Tags not yet merged whose names differ only by case, spacing or punctuation.
  $loose = [];
  foreach ($byType as $list) foreach ($list as $t) if ($t['canonical_name'] === null) $loose[] = (string) $t['name'];
  $alike = \App\LookAlikes::groups($loose);
  ?>
  <?php if ($alike !== []): ?>
  <div class="facts-now">
    <p><?= \App\Lang::t('tagsadmin.alike_intro') ?></p>
    <ul>
      <?php foreach ($alike as $group): ?><li><?= $this->e(implode(', ', $group)) ?></li><?php endforeach; ?>
    </ul>
    <p><a href="/wrangling"><?= \App\Lang::t('tagsadmin.alike_link') ?></a></p>
  </div>
  <?php endif; ?>
  <?php /* Shown by the listfilter module only. */ ?>
  <p class="live-filter" hidden data-js-module="listfilter" data-filter-rows=".tag-rows > li">
    <label><?= \App\Lang::t('admin.filter_label') ?> <input type="search" autocomplete="off"></label>
    <span class="chapter-meta" data-filter-none role="status" hidden><?= \App\Lang::t('admin.filter_none') ?></span>
  </p>
  <?php foreach ($types as $type): ?>
  <h2><?= $this->e($type['name']) ?></h2>
  <div class="queue-actions">
    <form method="post" action="/tags/movetype/<?= (int) $type['id'] ?>/up" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_up') ?></button>
    </form>
    <form method="post" action="/tags/movetype/<?= (int) $type['id'] ?>/down" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('taxonomy.move_down') ?></button>
    </form>
    <a class="btn" href="/tags/edittype/<?= (int) $type['id'] ?>"><?= \App\Lang::t('tagsadmin.rename_type') ?></a>
    <a class="btn" href="/tags/deletetype/<?= (int) $type['id'] ?>"><?= \App\Lang::t('tagsadmin.delete_type') ?></a>
  </div>
  <?php if (empty($byType[(int) $type['id']])): ?><p class="chapter-meta"><?= \App\Lang::t('tagsadmin.no_tags') ?></p><?php else: ?>
  <ul class="admin-rows tag-rows">
    <?php foreach ($byType[(int) $type['id']] as $t): ?>
    <li>
      <strong><?= $this->e($t['name']) ?></strong>
      <?php if ($t['canonical_name'] !== null): ?><span class="chapter-meta"><?= \App\Lang::t('tagsadmin.merged_into') ?> <?= $this->e($t['canonical_name']) ?></span><?php endif; ?>
      <span class="chapter-meta"><?= \App\Lang::t('tagsadmin.story_count', ['n' => (int) $t['story_count']]) ?></span>
      <div class="queue-actions">
      <a class="btn" href="/tags/edit/<?= (int) $t['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
      <a class="btn" href="/tags/delete/<?= (int) $t['id'] ?>"><?= \App\Lang::t('common.delete') ?></a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($types !== []): ?>
  <h2><?= \App\Lang::t('tagsadmin.add_tag_heading') ?></h2>
  <form method="post" class="form-card" action="/tags/addtag">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('tagsadmin.type') ?>
      <select name="tag_type_id" required>
        <?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"<?= (int) $type['id'] === $focusType ? ' selected' : '' ?>><?= $this->e($type['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60"<?= $focusType > 0 ? ' autofocus' : '' ?><?php if ($isRelationships): ?> placeholder="<?= $this->e(\App\Lang::t('tagsadmin.relationships_placeholder')) ?>"<?php endif; ?>></label>
    <?php if ($isRelationships): ?><p class="chapter-meta"><?= \App\Lang::t('tagsadmin.relationships_help') ?></p><?php endif; ?>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
  <?php endif; ?>
  <h2><?= \App\Lang::t('tagsadmin.add_type_heading') ?></h2>
  <form method="post" class="form-card" action="/tags/addtype">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="60"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
</section>
