<?php // app/views/mailusers/sent.php ?>
<?php $this->layout('layout'); ?>
<section>
  <h1><?= \App\Lang::t('mailusers.sent_heading') ?></h1>
  <p><?= \App\Lang::t('mailusers.sent_result', ['sent' => number_format($mailed), 'total' => number_format($total)]) ?></p>
  <p><a href="/mailusers"><?= \App\Lang::t('mailusers.back') ?></a></p>
</section>
