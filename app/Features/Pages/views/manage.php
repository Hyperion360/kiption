<?php // app/views/pages/manage.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('pages.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('pages.intro') ?></p>
  <p><a class="btn" href="/page/new"><?= \App\Lang::t('pages.add') ?></a></p>
  <table class="stack-table">
    <thead><tr><th scope="col"><?= \App\Lang::t('common.title') ?></th><th scope="col"><?= \App\Lang::t('page.slug') ?></th><th scope="col"><?= \App\Lang::t('pages.actions') ?></th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/page/view/<?= $this->e($r['slug']) ?>"><?= $this->e($r['title']) ?></a></td>
        <td data-label="<?= $this->e(\App\Lang::t('page.slug')) ?>"><?= $this->e('/page/view/' . $r['slug']) ?></td>
        <td><div class="row-actions">
          <a class="btn" href="/page/edit/<?= $this->e($r['slug']) ?>"><?= \App\Lang::t('pages.edit') ?></a>
          <?php if (!in_array($r['slug'], $protected, true)): ?><a class="btn" href="/page/remove/<?= $this->e($r['slug']) ?>"><?= \App\Lang::t('common.delete') ?></a><?php endif; ?>
        </div></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</section>
