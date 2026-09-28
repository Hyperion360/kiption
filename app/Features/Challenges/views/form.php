<?php // app/views/challenges/form.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= $row === null ? \App\Lang::t('challenges.new') : \App\Lang::t('challenges.edit') ?></h1>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= $row === null ? '/challenges/create' : '/challenges/update/' . $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('common.title') ?> <input name="title" required maxlength="120" value="<?= $this->e($row['title'] ?? '') ?>"></label>
    <label><?= \App\Lang::t('common.summary') ?> <textarea name="summary" maxlength="2000" rows="3"><?= $this->e($row['summary'] ?? '') ?></textarea></label>
    <label><?= \App\Lang::t('challenges.membership') ?>
      <select name="membership">
        <?php foreach (['open', 'moderated', 'closed'] as $m): ?>
          <option value="<?= $m ?>"<?= ($row['membership'] ?? 'open') === $m ? ' selected' : '' ?>><?= $this->e(\App\Lang::t('challenges.m_' . $m)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit"><?= $row === null ? \App\Lang::t('common.create') : \App\Lang::t('common.save') ?></button>
  </form>
  <?php if ($row !== null): ?>
  <form method="post" action="/challenges/delete/<?= $this->e($row['slug']) ?>" class="inline">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <button type="submit"><?= \App\Lang::t('common.delete') ?></button>
  </form>
  <?php endif; ?>
  <?php if ($row !== null): ?>
  <h2><?= \App\Lang::t('challenges.prompts_heading') ?></h2>
  <ol>
    <?php foreach ($prompts as $p): ?>
      <li>
        <?= $this->e($p['prompt_text']) ?>
        <form method="post" action="/challenges/promptmove/<?= $this->e($row['slug']) ?>/<?= (int) $p['id'] ?>/up" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('challenges.move_up') ?></button>
        </form>
        <form method="post" action="/challenges/promptmove/<?= $this->e($row['slug']) ?>/<?= (int) $p['id'] ?>/down" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('challenges.move_down') ?></button>
        </form>
        <form method="post" action="/challenges/promptremove/<?= $this->e($row['slug']) ?>/<?= (int) $p['id'] ?>" class="inline">
          <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
          <button type="submit"><?= \App\Lang::t('common.remove') ?></button>
        </form>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($prompts === []): ?><p class="chapter-meta"><?= \App\Lang::t('challenges.prompts_empty') ?></p><?php endif; ?>
  <form method="post" action="/challenges/prompt/<?= $this->e($row['slug']) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('challenges.prompt_text') ?> <textarea name="prompt_text" maxlength="500" rows="2" required></textarea></label>
    <button type="submit"><?= \App\Lang::t('challenges.add_prompt') ?></button>
  </form>
  <?php endif; ?>
</section>
