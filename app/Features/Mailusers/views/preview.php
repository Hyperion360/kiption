<?php // app/views/mailusers/preview.php ?>
<?php $this->layout('layout'); ?>
<?php $fits = (($usage ?? null) === null) || (($fit ?? null) !== null && $fit['fits']); ?>
<section>
  <h1><?= \App\Lang::t('mailusers.preview_heading') ?></h1>
  <p><strong><?= \App\Lang::t('mailusers.recipient_count', ['n' => number_format($count)]) ?></strong></p>
  <?php if (($usage ?? null) !== null): ?>
  <div class="facts-now">
    <p><?= \App\Lang::t('mailusers.usage_day', ['sent' => number_format($usage['today']), 'total' => number_format($usage['dailyUsable'])]) ?></p>
    <?php if ($usage['monthlyCap'] > 0): ?>
    <p><?= \App\Lang::t('mailusers.usage_month', ['sent' => number_format($usage['month']), 'total' => number_format($usage['monthlyCap'])]) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <p><?= \App\Lang::t('mailusers.subject') ?>: <strong><?= $this->e($subject) ?></strong></p>
  <pre class="mail-preview"><?= $this->e($body) ?></pre>
  <?php if (($usage ?? null) !== null): ?>
  <?php if ($fits): ?><p class="notice"><?= \App\Lang::t('mailusers.fits') ?></p>
  <?php else: ?><p class="error"><?= \App\Lang::t('mailusers.fits_not') ?></p><?php endif; ?>
  <p class="meta"><?= \App\Lang::t('mailusers.fits_advisory') ?></p>
  <?php endif; ?>
  <form method="post" class="form-card" action="/mailusers/send">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <input type="hidden" name="subject" value="<?= $this->e($subject) ?>">
    <input type="hidden" name="body" value="<?= $this->e($body) ?>">
    <button type="submit"<?= $fits ? '' : ' disabled' ?>><?= \App\Lang::t('mailusers.send') ?></button>
    <a href="/mailusers"><?= \App\Lang::t('mailusers.edit_again') ?></a>
  </form>
</section>
