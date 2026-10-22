<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/* Printable A4 poster with the gate-sales QR (links to /?from=gate). */

require_role('admin');
$s = settings();
$gateUrl = url('/?from=gate');
$qr = base64_encode(qr_png($gateUrl, 16));
$price = price_paise('gate');
$accent = accent_color();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Gate poster · <?= e($s['event_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Inter:wght@500;600&display=swap" rel="stylesheet">
<style>
  @page { size: A4; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: #ddd; font-family: Inter, system-ui, sans-serif; color: #141414; }
  .sheet { width: 210mm; height: 297mm; margin: 10mm auto; background: #fff; padding: 18mm 16mm; display: flex; flex-direction: column; align-items: center; text-align: center; border-top: 14mm solid <?= e($accent) ?>; }
  .eyebrow { text-transform: uppercase; letter-spacing: .14em; font-weight: 600; font-size: 13pt; color: #555; margin: 0; }
  h1 { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 40pt; line-height: 1.02; margin: 6mm 0 0; letter-spacing: -.02em; }
  .cta { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 56pt; line-height: 1; margin: 12mm 0 8mm; color: <?= e($accent) ?>; letter-spacing: -.02em; }
  .qr { width: 120mm; height: 120mm; image-rendering: pixelated; }
  .steps { display: flex; gap: 8mm; margin-top: 8mm; font-size: 13pt; font-weight: 600; }
  .steps span { display: inline-flex; align-items: center; gap: 3mm; }
  .steps b { display: inline-grid; place-items: center; width: 9mm; height: 9mm; border-radius: 50%; background: #141414; color: #fff; font-size: 11pt; }
  .price { margin-top: auto; font-size: 16pt; font-weight: 600; }
  .url { font-size: 10pt; color: #777; margin-top: 2mm; word-break: break-all; }
  .toolbar { text-align: center; margin: 16px; }
  .toolbar button { font: 600 15px Inter, sans-serif; padding: 12px 24px; border-radius: 999px; border: 0; background: #141414; color: #fff; cursor: pointer; }
  @media print { html, body { background: #fff; } .sheet { margin: 0; } .toolbar { display: none; } }
</style>
</head>
<body>
<div class="toolbar">
  <?php if ($s['gate_sales_open'] !== '1'): ?><p style="color:#c0392b;font-weight:600">Gate sales are switched off. Turn them on in Event settings before the event.</p><?php endif; ?>
  <button onclick="window.print()">Print poster</button>
</div>
<div class="sheet">
  <p class="eyebrow"><?= e(fmt_datetime($s['event_datetime'], 'l, j F')) ?></p>
  <h1><?= e($s['event_name']) ?></h1>
  <div class="cta">Scan to buy tickets</div>
  <img class="qr" src="data:image/png;base64,<?= $qr ?>" alt="QR code to buy tickets">
  <div class="steps">
    <span><b>1</b> Scan</span><span><b>2</b> Pay with UPI</span><span><b>3</b> Show QR at entry</span>
  </div>
  <div class="price"><?= e(money($price)) ?> per person</div>
  <div class="url"><?= e($gateUrl) ?></div>
</div>
</body>
</html>
