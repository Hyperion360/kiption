<?php // app/views/notice.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($title) ?></h1>
  <div class="prose"><?= $body /* App\Notices::html: Markdown, every character escaped */ ?></div>
</article>
