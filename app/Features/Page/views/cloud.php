<?php // app/Features/Page/views/cloud.php
/** @var array{slug:string,title:string,body:string,updated_at:string} $page
 *  @var array $marketing */
// The simplified /cloud page every install ships: the page row's own title
// and text (admin-editable) plus one link to the hosted-service URL from the
// cloud_url setting. Nothing about plans or prices lives here; the full
// landing (cloud_landing) renders only where a flagship config supplies it.
$url = (string) ($cloudUrl ?? '');
?>
<?php $this->layout('layout'); ?>
<article class="cloud-page cloud-simple">
  <h1><?= $this->e((string) $page['title']) ?></h1>
  <div class="prose"><?= \App\Markdown::render((string) $page['body']) /* markdown at rest; raw HTML cannot be stored */ ?></div>
<?php if ($url !== ''): ?>
  <p class="cloud-link"><a class="cta-primary" href="<?= $this->e($url) ?>"><?= $this->e(\App\Lang::t('cloud.learn_more')) ?></a></p>
<?php endif; ?>
</article>
