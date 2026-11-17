<?php // app/Features/Page/views/_cloud_plans.php
/** The plan cards and launch-offer callout shared by both /cloud shapes.
 *  @var array $m @var array $plans @var string $ctaUrl @var string $tag the plan-name heading level */
$tag = in_array($tag ?? 'h2', ['h2', 'h3'], true) ? $tag : 'h2';
?>
<p class="plans-label"><?= $this->e((string) ($m['plans_label'] ?? 'The plans')) ?></p>
<div class="plan-grid">
<?php foreach ($plans as $p): if (!is_array($p)) continue; ?>
  <div class="plan-card<?= !empty($p['featured']) ? ' plan-featured' : '' ?>">
    <<?= $tag ?> class="plan-name"><?= $this->e((string) ($p['name'] ?? '')) ?><?php if (!empty($p['badge'])): ?><span class="plan-rec"><?= $this->e((string) $p['badge']) ?></span><?php endif; ?></<?= $tag ?>>
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
