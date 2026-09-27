<?php // app/views/wrangling/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('wrangling.heading') ?></h1>
  <p class="chapter-meta"><?= \App\Lang::t('wrangling.help') ?></p>
  <?php foreach ($groups as $typeName => $tags): ?>
  <h2><?= $this->e($typeName) ?></h2>
  <table>
    <tr>
      <th scope="col"><?= \App\Lang::t('wrangling.tag') ?></th>
      <th scope="col"><?= \App\Lang::t('wrangling.stories') ?></th>
      <th scope="col"><?= \App\Lang::t('wrangling.canonical') ?></th>
      <th scope="col"><?= \App\Lang::t('wrangling.actions') ?></th>
    </tr>
    <?php foreach ($tags as $t): ?>
    <tr>
      <td><?= $this->e($t['name']) ?></td>
      <td><?= (int) $t['story_count'] ?></td>
      <td><?= $t['canonical_id'] !== null ? $this->e($t['canonical_name']) : '' ?></td>
      <td>
        <a href="/wrangling/merge?synonym=<?= (int) $t['id'] ?>"><?= \App\Lang::t('wrangling.merge_link') ?></a>
        <?php if ($t['canonical_id'] !== null): ?>
        <form method="post" action="/wrangling/unmerge/<?= (int) $t['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('wrangling.unmerge') ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endforeach; ?>
</section>
