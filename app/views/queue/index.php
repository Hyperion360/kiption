<?php // app/views/queue/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1>Validation queue</h1>
  <?php
  $groups = ['story' => [], 'chapter' => [], 'member' => []];
  foreach ($rows as $r) { if ($r['k'] !== '0gate') $groups[$r['k']][] = $r; }
  ?>
  <h2>Stories</h2>
  <?php if ($groups['story'] === []): ?><p class="chapter-meta">Nothing waiting.</p><?php else: ?>
  <ul>
    <?php foreach ($groups['story'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong> by <?= $this->e($r['d']) ?>
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Approve</button>
        </form>
        <form method="post" action="/queue/story/<?= (int) $r['a'] ?>/remove" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Remove</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2>Chapters</h2>
  <?php if ($groups['chapter'] === []): ?><p class="chapter-meta">Nothing waiting.</p><?php else: ?>
  <ul>
    <?php foreach ($groups['chapter'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b'] !== '' ? $r['b'] : 'untitled') ?></strong> in <a href="/story/edit/<?= $this->e($r['c']) ?>"><?= $this->e($r['c']) ?></a> by <?= $this->e($r['d']) ?>
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Approve</button>
        </form>
        <form method="post" action="/queue/chapter/<?= (int) $r['a'] ?>/remove" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Remove</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2>Members awaiting approval</h2>
  <?php if ($groups['member'] === []): ?><p class="chapter-meta">Nobody waiting.</p><?php else: ?>
  <ul>
    <?php foreach ($groups['member'] as $r): ?>
      <li>
        <strong><?= $this->e($r['b']) ?></strong> (<?= $this->e($r['c']) ?>)
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/approve" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Approve</button>
        </form>
        <form method="post" action="/queue/member/<?= (int) $r['a'] ?>/reject" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>"><button type="submit">Reject</button>
        </form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
