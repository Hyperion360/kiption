<?php // app/Features/Page/views/cloud.php
/** @var array{slug:string,title:string,body:string,updated_at:string} $page
 *  @var array $marketing */
// The hosted-page landing shape: hero (kicker + title split + the body's own
// first paragraph as the lede), plan cards and the launch-offer callout from
// the cloud_marketing config, the page body as the long-form middle, and a
// close band repeating the header's promise. No config: hero and body only.
$m = is_array($marketing ?? null) ? $marketing : [];
$ctaLabel = (string) ($m['cta_label'] ?? '');
$ctaUrl = (string) ($m['cta_url'] ?? '');
$plans = isset($m['plans']) && is_array($m['plans']) ? $m['plans'] : [];
$close = isset($m['close']) && is_array($m['close']) ? $m['close'] : null;

$title = (string) $page['title'];
$colon = strpos($title, ': ');
$kicker = $colon !== false ? substr($title, 0, $colon) : '';
$h1 = $colon !== false ? substr($title, $colon + 2) : $title;

// The lede is the body's first paragraph (the subheader naming how the claim
// works); the rest keeps its own rhythm below the plans.
$body = (string) $page['body'];
$break = strpos($body, "\n\n");
$lede = $break !== false ? substr($body, 0, $break) : '';
$rest = $break !== false ? substr($body, $break + 2) : $body;
?>
<?php $this->layout('layout'); ?>
<article class="cloud-page">
  <header class="cloud-hero">
<?php if ($kicker !== ''): ?>
    <p class="cloud-kicker"><?= $this->e($kicker) ?></p>
<?php endif; ?>
    <h1><?= $this->e($h1) ?></h1>
<?php if ($lede !== ''): ?>
    <div class="cloud-lede"><?= \App\Markdown::render($lede) ?></div>
<?php endif; ?>
<?php if ($ctaUrl !== ''): ?>
    <p class="cta-row">
      <a class="cta-primary" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a>
      <?php if ($plans !== []): ?><a class="cta-secondary" href="#plans"><?= $this->e((string) ($m['plans_cta'] ?? 'See the plans')) ?></a><?php endif; ?>
    </p>
<?php endif; ?>
<?php if (!empty($m['proof'])): ?>
    <p class="proof-line"><?= $this->e((string) $m['proof']) ?></p>
<?php endif; ?>
  </header>

<?php if ($plans !== []): ?>
  <section class="plans-band" id="plans">
    <p class="plans-label"><?= $this->e((string) ($m['plans_label'] ?? 'The plans')) ?></p>
    <div class="plan-grid">
<?php foreach ($plans as $p): if (!is_array($p)) continue; ?>
      <div class="plan-card<?= !empty($p['featured']) ? ' plan-featured' : '' ?>">
        <h2 class="plan-name"><?= $this->e((string) ($p['name'] ?? '')) ?><?php if (!empty($p['badge'])): ?><span class="plan-rec"><?= $this->e((string) $p['badge']) ?></span><?php endif; ?></h2>
        <p class="plan-for"><?= $this->e((string) ($p['blurb'] ?? '')) ?></p>
        <p class="plan-price"><?= $this->e((string) ($p['price'] ?? '')) ?><small> <?= $this->e((string) ($p['period'] ?? '')) ?></small></p>
<?php if (!empty($p['alt'])): ?>
        <p class="plan-alt"><?= $this->e((string) $p['alt']) ?></p>
<?php endif; ?>
<?php if (!empty($p['rows']) && is_array($p['rows'])): ?>
        <ul class="plan-rows">
<?php foreach ($p['rows'] as $row): ?>
          <li><?= $this->e((string) $row) ?></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
<?php if (!empty($p['note'])): ?>
        <p class="plan-note"><?= $this->e((string) $p['note']) ?></p>
<?php endif; ?>
        <a class="cta-primary" href="<?= $this->e((string) ($p['url'] ?? $ctaUrl)) ?>"><?= $this->e((string) ($p['cta'] ?? $ctaLabel)) ?></a>
      </div>
<?php endforeach; ?>
    </div>
<?php if (!empty($m['offer'])): ?>
    <div class="offer-callout"><?= \App\Markdown::render((string) $m['offer']) ?></div>
<?php endif; ?>
  </section>
<?php endif; ?>

  <section class="cloud-body">
    <div class="prose"><?= \App\Markdown::render($rest) /* markdown at rest; raw HTML cannot be stored */ ?></div>
    <p class="chapter-meta"><?= \App\Lang::t('page.last_updated', ['date' => $this->e(substr($page['updated_at'], 0, 10))]) ?></p>
  </section>

<?php if ($close !== null && $ctaUrl !== ''): ?>
  <section class="close-band">
    <h2><?= $this->e((string) ($close['heading'] ?? $ctaLabel)) ?></h2>
<?php if (!empty($close['body'])): ?>
    <p><?= $this->e((string) $close['body']) ?></p>
<?php endif; ?>
    <a class="cta-primary" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a>
  </section>
<?php endif; ?>
</article>
