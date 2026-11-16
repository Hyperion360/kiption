<?php // app/views/mailusers/index.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('mailusers.heading') ?></h1>
  <p class="lede"><?= \App\Lang::t('mailusers.intro') ?></p>
  <?php if (($error ?? null) !== null): ?><p class="error"><?= $this->e($error) ?></p><?php endif; ?>
  <?php if (($now ?? null) !== null): ?>
  <div class="facts-now">
    <p><?= \App\Lang::t($now['n'] === 0 ? 'mailusers.reach_none' : ($now['n'] === 1 ? 'mailusers.reach_one' : 'mailusers.reach'), ['n' => $now['n']]) ?></p>
    <p><?= $now['last_at'] === null ? \App\Lang::t('mailusers.last_never') : \App\Lang::t('mailusers.last_sent', ['date' => $this->e(\App\Dates::format((string) $now['last_at'], true, true)), 'subject' => $this->e((string) $now['last_subject'])]) ?></p>
  </div>
  <?php endif; ?>
  <?php // The same numbers the sending check uses; with no cap set nothing renders at all. ?>
  <?php if (($usage ?? null) !== null): ?>
  <div class="facts-now">
    <p><?= \App\Lang::t('mailusers.usage_day', ['sent' => number_format($usage['today']), 'total' => number_format($usage['dailyUsable'])]) ?></p>
    <?php if ($usage['monthlyCap'] > 0): ?>
    <p><?= \App\Lang::t('mailusers.usage_month', ['sent' => number_format($usage['month']), 'total' => number_format($usage['monthlyCap'])]) ?></p>
    <?php endif; ?>
    <p><?= \App\Lang::t('mailusers.usage_help') ?></p>
    <?php if (5 * $usage['today'] >= 4 * $usage['dailyUsable'] || ($usage['monthlyCap'] > 0 && 5 * $usage['month'] >= 4 * $usage['monthlyCap'])): ?>
    <p class="error"><?= \App\Lang::t('mailusers.warn_close') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <form method="post" class="form-card" action="/mailusers/preview">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label><?= \App\Lang::t('mailusers.subject') ?> <input name="subject" required maxlength="200" value="<?= $this->e($subject) ?>"></label>
    <label><?= \App\Lang::t('mailusers.body') ?> <textarea name="body" rows="10" required maxlength="20000"><?= $this->e($body) ?></textarea></label>
    <button type="submit"><?= \App\Lang::t('mailusers.preview') ?></button>
  </form>
</section>
