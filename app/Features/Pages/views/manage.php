<?php // app/views/pages/manage.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('pages.heading') ?></h1>
  <p><a href="/page/new"><?= \App\Lang::t('pages.add') ?></a></p>
  <table>
    <thead><tr><th><?= \App\Lang::t('common.title') ?></th><th><?= \App\Lang::t('page.slug') ?></th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/page/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a></td>
        <td><?= $this->e($r['slug']) ?></td>
        <td>
          <a href="/page/edit/<?= $this->e($r['slug']) ?>"><?= \App\Lang::t('pages.edit') ?></a>
          <?php if (!in_array($r['slug'], $protected, true)): ?><a href="/page/remove/<?= $this->e($r['slug']) ?>"><?= \App\Lang::t('page.delete') ?></a><?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
