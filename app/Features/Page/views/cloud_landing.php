<?php // app/Features/Page/views/cloud_landing.php
/** @var array{slug:string,title:string,body:string,updated_at:string} $page
 *  @var array $marketing */
// The full landing shape, driven by cloud_marketing['landing']: page nav, hero
// with a writing-screen mock, the proof band, feature-and-objection blocks
// with decorative product mocks, the offer band, plans, FAQ, and the close.
// Structure only: every word, link, and price comes from the config, and any
// missing block is skipped. Server-rendered, no script: the FAQ is <details>
// and the mocks are aria-hidden pictures of the product.
$m = is_array($marketing ?? null) ? $marketing : [];
$lp = is_array($m['landing'] ?? null) ? $m['landing'] : [];
$arr = static fn($v): array => is_array($v) ? $v : [];
$str = static fn($v): string => is_string($v) || is_numeric($v) ? (string) $v : '';
$ctaLabel = $str($m['cta_label'] ?? '');
$ctaUrl = $str($m['cta_url'] ?? '');
$plans = $arr($m['plans'] ?? []);
$close = $arr($m['close'] ?? []);
$hero = $arr($lp['hero'] ?? []);
$ed = $arr($lp['editor'] ?? []);
$why = $arr($lp['why'] ?? []);
$how = $arr($lp['how'] ?? []);
$offer = $arr($lp['offer'] ?? []);
$pricing = $arr($lp['pricing'] ?? []);
$faq = $arr($lp['faq'] ?? []);
$rest = (string) $page['body'];
$themes = $arr($ed['themes'] ?? []);
?>
<?php $this->layout('layout'); ?>
<article class="cloud-page cloud-landing">
<?php if ($arr($lp['nav'] ?? []) !== []): ?>
  <nav class="cloud-nav" aria-label="<?= $this->e($str($lp['nav_label'] ?? 'Sections')) ?>">
<?php foreach ($arr($lp['nav']) as $item): if (!is_array($item) || count($item) < 2) continue; ?>
    <a href="<?= $this->e($str($item[1])) ?>"><?= $this->e($str($item[0])) ?></a>
<?php endforeach; ?>
<?php if ($ctaUrl !== ''): ?>
    <a class="cta-primary cloud-nav-cta" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a>
<?php endif; ?>
  </nav>
<?php endif; ?>

  <header class="lp-hero">
    <div class="lp-hero-copy">
<?php if ($str($hero['kicker'] ?? '') !== ''): ?>
      <p class="cloud-kicker"><?= $this->e($str($hero['kicker'])) ?></p>
<?php endif; ?>
      <h1><?= $this->e($str($hero['h1'] ?? $page['title'])) ?></h1>
<?php if ($str($hero['lede'] ?? '') !== ''): ?>
      <p class="lp-lede"><?= $this->e($str($hero['lede'])) ?></p>
<?php endif; ?>
<?php if ($ctaUrl !== ''): ?>
      <p class="cta-row">
        <a class="cta-primary" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a>
<?php if ($str($hero['from'] ?? '') !== ''): ?><span class="lp-from"><?= $this->e($str($hero['from'])) ?></span><?php endif; ?>
<?php $sec = $arr($hero['secondary'] ?? []); if (count($sec) >= 2): ?><a class="cta-secondary" href="<?= $this->e($str($sec[1])) ?>"><?= $this->e($str($sec[0])) ?></a><?php endif; ?>
      </p>
<?php endif; ?>
<?php if ($arr($hero['trust'] ?? []) !== []): ?>
      <ul class="lp-trust">
<?php foreach ($arr($hero['trust']) as $t): ?>
        <li><?= $this->e($str($t)) ?></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </div>
<?php if ($ed !== []): ?>
    <figure class="lp-mock lp-editor">
      <div class="lp-mock-bar"><span><?= $this->e($str($ed['story'] ?? '')) ?></span><span><?= $this->e($str($ed['chapter'] ?? '') . ' · ' . $str($ed['status'] ?? '')) ?></span></div>
      <div class="lp-mock-tools" aria-hidden="true"><?php foreach ($arr($ed['tools'] ?? []) as $tool): ?><span><?= $this->e($str($tool)) ?></span><?php endforeach; ?></div>
      <div class="lp-mock-page" aria-hidden="true"><?php foreach ($arr($ed['text'] ?? []) as $para): ?><p><?= $this->e($str($para)) ?></p><?php endforeach; ?></div>
      <div class="lp-mock-foot" aria-hidden="true"><?php foreach ($themes as $i => $th): ?><span<?= $i === 0 ? ' class="on"' : '' ?>><?= $this->e($str($th)) ?></span><?php endforeach; ?><span class="lp-mock-focus"><?= $this->e($str($ed['focus'] ?? '')) ?></span></div>
      <figcaption><?= $this->e($str($ed['caption'] ?? '')) ?></figcaption>
    </figure>
<?php endif; ?>
  </header>

<?php if ($why !== []): ?>
  <section class="lp-band" id="why-archives-close" aria-labelledby="lp-why-h">
    <p class="lp-label"><?= $this->e($str($why['label'] ?? '')) ?></p>
    <h2 id="lp-why-h"><?= $this->e($str($why['h2'] ?? '')) ?></h2>
    <p class="lp-lead"><?= $this->e($str($why['intro'] ?? '')) ?>
<?php $src = $arr($why['source'] ?? []); if (count($src) >= 2): ?> <a href="<?= $this->e($str($src[1])) ?>" rel="noopener"><?= $this->e($str($src[0])) ?></a><?php endif; ?></p>
    <ol class="lp-why">
<?php foreach ($arr($why['items'] ?? []) as $i => $line): ?>
      <li><span><?= sprintf('%02d', $i + 1) ?></span> <?= $this->e($str($line)) ?></li>
<?php endforeach; ?>
    </ol>
  </section>
<?php endif; ?>

<?php if ($arr($how['features'] ?? []) !== []): ?>
  <section class="lp-band" id="how-it-works" aria-labelledby="lp-how-h">
    <p class="lp-label"><?= $this->e($str($how['label'] ?? '')) ?></p>
    <h2 id="lp-how-h"><?= $this->e($str($how['h2'] ?? '')) ?></h2>
<?php $n = 0; foreach ($arr($how['features']) as $f): if (!is_array($f)) continue; $n++; $mock = $arr($f['mock'] ?? []); $type = $str($mock['type'] ?? ''); ?>
    <div class="lp-feature" id="feature-<?= $this->e(preg_replace('/[^a-z0-9-]/', '', $str($f['id'] ?? (string) $n))) ?>">
      <div class="lp-feature-copy">
        <p class="lp-num"><?= sprintf('%02d', $n) ?></p>
        <h3><?= $this->e($str($f['title'] ?? '')) ?></h3>
        <p><?= $this->e($str($f['body'] ?? '')) ?></p>
        <blockquote><?= $this->e($str($f['objection'] ?? '')) ?></blockquote>
        <p><?= $this->e($str($f['answer'] ?? '')) ?></p>
      </div>
<?php if ($type !== ''): ?>
      <div class="lp-feature-mock" aria-hidden="true">
<?php if ($type === 'editor'): ?>
        <div class="lp-mock">
          <div class="lp-mock-page"><?php $paras = $arr($ed['text'] ?? []); foreach ($paras as $i => $para): ?><?= $i === 1 ? '<p class="lp-scene">* * *</p>' : '' ?><p><?= $this->e($str($para)) ?></p><?php endforeach; ?></div>
          <div class="lp-mock-foot"><?php foreach ($themes as $i => $th): ?><span<?= $i === 1 ? ' class="on"' : '' ?>><?= $this->e($str($th)) ?></span><?php endforeach; ?></div>
        </div>
<?php elseif ($type === 'report'): ?>
        <div class="lp-mock">
          <p class="lp-mock-title"><?= $this->e($str($mock['title'] ?? '')) ?></p>
          <p class="lp-mock-sub"><?= $this->e($str($mock['sub'] ?? '')) ?></p>
          <table class="lp-table"><thead><tr><th></th><?php foreach ($arr($mock['cols'] ?? []) as $c): ?><th><?= $this->e($str($c)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($arr($mock['rows'] ?? []) as $r): $r = $arr($r); ?><tr><th><?= $this->e($str($r[0] ?? '')) ?></th><td><?= $this->e($str($r[1] ?? '')) ?></td><td><?= $this->e($str($r[2] ?? '')) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <p class="lp-mock-sub"><?= $this->e($str($mock['foot'] ?? '')) ?></p>
        </div>
<?php elseif ($type === 'log'): ?>
        <div class="lp-mock">
          <p class="lp-mock-title"><?= $this->e($str($mock['title'] ?? '')) ?></p>
          <ul class="lp-log"><?php foreach ($arr($mock['items'] ?? []) as $row): $row = $arr($row); ?><li><span><?= $this->e($str($row[0] ?? '')) ?></span><em><?= $this->e($str($row[1] ?? '')) ?></em></li><?php endforeach; ?></ul>
          <p class="lp-mock-sub"><?= $this->e($str($mock['foot'] ?? '')) ?></p>
        </div>
<?php elseif ($type === 'chips'): ?>
        <ul class="lp-chips"><?php foreach ($arr($mock['items'] ?? []) as $chip): ?><li><?= $this->e($str($chip)) ?></li><?php endforeach; ?></ul>
<?php elseif ($type === 'file'): ?>
        <div class="lp-mock">
          <p class="lp-mock-title"><?= $this->e($str($mock['title'] ?? '')) ?></p>
          <p class="lp-mock-sub"><?= $this->e($str($mock['sub'] ?? '')) ?></p>
          <p class="lp-dl"><?php foreach ($arr($mock['downloads'] ?? []) as $d): ?><span><?= $this->e($str($d)) ?></span><?php endforeach; ?></p>
        </div>
<?php endif; ?>
      </div>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($offer !== []): ?>
  <section class="lp-offer">
    <h2><?= $this->e($str($offer['heading'] ?? '')) ?></h2>
    <p><?= $this->e($str($offer['body'] ?? '')) ?><?php $ol = $arr($offer['link'] ?? []); if (count($ol) >= 2): ?> <a href="<?= $this->e($str($ol[1])) ?>"><?= $this->e($str($ol[0])) ?></a><?php endif; ?></p>
<?php if ($ctaUrl !== ''): ?>
    <p><a class="cta-primary" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a></p>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($plans !== []): ?>
  <section class="lp-band" id="pricing" aria-labelledby="lp-pricing-h">
    <p class="lp-label"><?= $this->e($str($pricing['label'] ?? '')) ?></p>
    <h2 id="lp-pricing-h"><?= $this->e($str($pricing['h2'] ?? '')) ?></h2>
    <p class="lp-lead"><?= $this->e($str($pricing['sub'] ?? '')) ?></p>
    <div class="plans-band" id="plans">
<?= $this->render('page/_cloud_plans', ['m' => ['plans_label' => '', 'offer' => ''] + $m, 'plans' => $plans, 'ctaUrl' => $ctaUrl, 'tag' => 'h3']) ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($arr($faq['items'] ?? []) !== []): ?>
  <section class="lp-band" id="faq" aria-labelledby="lp-faq-h">
    <div class="lp-faq-head">
    <p class="lp-label"><?= $this->e($str($faq['label'] ?? '')) ?></p>
    <h2 id="lp-faq-h"><?= $this->e($str($faq['h2'] ?? '')) ?></h2>
    <p class="lp-lead"><?= $this->e($str($faq['sub'] ?? '')) ?></p>
    </div>
    <div class="lp-faq-list">
<?php foreach ($arr($faq['items']) as $qa): $qa = $arr($qa); ?>
    <details class="lp-faq">
      <summary><?= $this->e($str($qa[0] ?? '')) ?></summary>
      <p><?= $this->e($str($qa[1] ?? '')) ?></p>
    </details>
<?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($close !== [] && $ctaUrl !== ''): ?>
  <section class="lp-close">
    <h2><?= $this->e($str($close['heading'] ?? $ctaLabel)) ?></h2>
<?php if ($str($close['body'] ?? '') !== ''): ?>
    <p><?= $this->e($str($close['body'])) ?></p>
<?php endif; ?>
    <p><a class="cta-primary" href="<?= $this->e($ctaUrl) ?>"><?= $this->e($ctaLabel) ?></a><?php if ($str($lp['close_note'] ?? '') !== ''): ?> <span class="lp-from"><?= $this->e($str($lp['close_note'])) ?></span><?php endif; ?></p>
  </section>
<?php endif; ?>

  <section class="cloud-body">
    <div class="prose"><?= \App\Markdown::render($rest) /* markdown at rest; raw HTML cannot be stored */ ?></div>
    <p class="chapter-meta"><?= \App\Lang::t('page.last_updated', ['date' => $this->e(\App\Dates::format($page['updated_at']))]) ?></p>
  </section>
</article>
