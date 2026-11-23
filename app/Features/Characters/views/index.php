<?php // app/Features/Characters/views/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('characters.heading') ?></h1>
  <p class="admin-help"><?= $helpHtml ?></p>
  <?php if ($categories === []): ?><p class="admin-help"><?= $this->e(\App\Lang::t('characters.need_category')) ?> <a href="/categories"><?= \App\Lang::t('categories.heading') ?></a></p><?php endif; ?>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if ($characters === []): ?><p class="chapter-meta"><?= \App\Lang::t('characters.none') ?></p><?php else: ?>
  <ul>
    <?php foreach ($characters as $c): ?>
    <li>
      <?= $this->e($c['name']) ?>
      <span class="chapter-meta"><?= $c['category_name'] === null ? \App\Lang::t('characters.any_category') : $this->e($c['category_name']) ?></span>
      <span class="chapter-meta"><?= \App\Lang::t('characters.story_count', ['n' => (int) $c['story_count']]) ?></span>
      <a href="/characters/edit/<?= (int) $c['id'] ?>"><?= \App\Lang::t('common.edit') ?></a>
      <a href="/characters/delete/<?= (int) $c['id'] ?>"><?= \App\Lang::t('common.delete') ?></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <h2><?= \App\Lang::t('characters.add_heading') ?></h2>
  <form method="post" class="form-card" action="/characters/create">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('taxonomy.name') ?> <input name="name" required maxlength="80"></label>
    <label><?= \App\Lang::t('characters.category') ?>
      <select name="category_id">
        <option value="0"><?= \App\Lang::t('characters.any_category') ?></option>
        <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>"><?= $this->e($cat['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label><?= \App\Lang::t('characters.description') ?> <input name="description" maxlength="500"></label>
    <button type="submit"><?= \App\Lang::t('common.create') ?></button>
  </form>
  <?php if (($conversion ?? null) !== null): $L = fn (string $k, array $p = []) => \App\Lang::t($k, $p); ?>
  <h2><?= $L('characters.convert_heading') ?></h2>
  <p class="admin-help"><?= $this->e($L('characters.convert_help')) ?></p>
  <p><?= $this->e($L('characters.convert_counts', ['n' => $conversion['characters'], 's' => $conversion['stories']])) ?></p>
  <?php if ($conversion['merges'] !== []): ?><p class="admin-help"><?= $this->e($L('characters.convert_merges')) ?></p>
  <ul><?php foreach ($conversion['merges'] as $kept => $others): ?><li><?= $this->e($kept) ?>: <?= $this->e(implode(', ', $others)) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($conversion['reused'] !== []): ?><p class="admin-help"><?= $this->e($L('characters.convert_reused')) ?></p>
  <ul><?php foreach ($conversion['reused'] as $n): ?><li><?= $this->e($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($conversion['categoryBound'] !== []): ?><p class="admin-help"><?= $this->e($L('characters.convert_category')) ?></p>
  <ul><?php foreach ($conversion['categoryBound'] as $b): ?><li><?= $this->e($b['name']) ?> <span class="chapter-meta"><?= $this->e($b['category']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($conversion['blocked'] !== []): ?><p class="error"><?= $this->e($L('characters.convert_blocked')) ?></p>
  <ul><?php foreach ($conversion['blocked'] as $n => $type): ?><li><?= $this->e($L('characters.convert_blocked_item', ['name' => $n, 'type' => $type])) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($conversion['invalid'] !== []): ?><p class="error"><?= $this->e($L('characters.convert_invalid')) ?></p>
  <ul><?php foreach ($conversion['invalid'] as $n): ?><li><?= $this->e($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($conversion['blocked'] === [] && $conversion['invalid'] === []): ?>
  <form method="post" class="form-card" action="/characters/convert">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= $this->e($L('characters.convert_confirm', ['word' => $confirmWord])) ?> <input name="confirm" required autocomplete="off" maxlength="20"></label>
    <button type="submit"><?= $L('characters.convert_button') ?></button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</section>
