<?php // app/views/queue/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('queue.heading') ?></h1>
  <?php
  $groups = ['story' => [], 'chapter' => [], 'member' => [], 'report' => []];
  foreach ($rows as $r) { if ($r['k'] !== '0gate') $groups[$r['k']][] = $r; }
  ?>
  <h2><?= \App\Lang::t('queue.stories') ?></h2>
  <?php if ($groups['story'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_waiting') ?></p><?php else: ?>
  <ul>
    <?php foreach ($groups['story'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong> <?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?>
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/remove" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.chapters') ?></h2>
  <?php if ($groups['chapter'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_waiting') ?></p><?php else: ?>
  <ul>
    <?php foreach ($groups['chapter'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b'] !== '' ? $r['b'] : \App\Lang::t('story.untitled')) ?></strong> <?= \App\Lang::t('queue.in') ?> <a href="/story/edit/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a> <?= \App\Lang::t('story.by') ?> <?= $this->e($r['d']) ?>
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/remove" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.members') ?></h2>
  <?php if ($groups['member'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nobody') ?></p><?php else: ?>
  <ul>
    <?php foreach ($groups['member'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong> (<?= $this->e($r['c']) ?>)
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.approve') ?></button>
        </form>
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/reject" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.reject') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('queue.reports') ?></h2>
  <?php if ($groups['report'] === []): ?><p class="chapter-meta"><?= \App\Lang::t('queue.nothing_reported') ?></p><?php else: ?>
  <ul>
    <?php foreach ($groups['report'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong>
        <?php if ($r['d'] !== null): ?><blockquote class="chapter-meta"><?= $this->e($r['d']) ?></blockquote><?php endif; ?>
        <?php if ($r['c'] !== ''): ?><?= \App\Lang::t('queue.on') ?> <a href="/story/view/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a><?php endif; ?>
        <form method="post" action="/report/resolve/<?= (int) $r['a'] ?>/dismiss" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit"><?= \App\Lang::t('queue.dismiss') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
