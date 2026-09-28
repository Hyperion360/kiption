<?php // app/views/lists/view.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $this->e($list['title']) ?></h1>
  <p class="chapter-meta">
    <?= \App\Lang::t('story.by') ?> <a href="/user/view/<?= $this->e($list['owner_slug']) ?>"><?= $this->e($list['owner_penname']) ?></a>
    | <?= \App\Lang::t('common.works_count', ['n' => number_format(count($items))]) ?>
    | <?= \App\Lang::t('story.published') ?> <?= $this->e(substr((string) $list['created_at'], 0, 10)) ?>
    <?php if ((int) $list['is_public'] !== 1): ?>| <?= \App\Lang::t('lists.private_label') ?><?php endif; ?>
  </p>
  <?php if ($list['summary'] !== ''): ?><p><?= $this->e($list['summary']) ?></p><?php endif; ?>
  <?php if ($isOwner): ?>
  <p>
    <a href="/lists/edit/<?= $this->e($list['slug']) ?>"><?= \App\Lang::t('common.edit') ?></a>
    <form method="post" action="/lists/delete/<?= $this->e($list['slug']) ?>" class="inline">
      <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
      <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
    </form>
  </p>
  <?php endif; ?>
  <ol>
    <?php foreach ($items as $it): ?>
      <li>
        <a href="/story/view/<?= $this->e($it['slug']) ?>"><?= $this->e($it['title']) ?></a>
        <?php if ((string) ($it['note'] ?? '') !== ''): ?><span class="chapter-meta"> - <?= $this->e($it['note']) ?></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($items === []): ?><p class="chapter-meta"><?= \App\Lang::t('lists.empty') ?></p><?php endif; ?>
</section>
