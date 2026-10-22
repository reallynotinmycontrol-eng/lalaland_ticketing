<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$user = require_role('staff');
$counts = gate_counts();

render_header('Scanner', [
    'nav'        => 'staff',
    'body_class' => 'page-scan',
    'head'       => '<meta name="csrf" content="' . e(csrf_token()) . '">',
]);
?>
<main class="scan">
  <div class="scan-counter" aria-live="polite">
    <span>Entered</span>
    <strong><span id="c-entered"><?= $counts['entered'] ?></span> / <span id="c-sold"><?= $counts['sold'] ?></span></strong>
    <span class="who"><?= e($user['name']) ?></span>
  </div>

  <div class="scan-tabs" role="tablist">
    <button role="tab" aria-selected="true" data-tab="camera">Scan QR</button>
    <button role="tab" aria-selected="false" data-tab="search">Search</button>
  </div>

  <section class="scan-pane" id="pane-camera">
    <div id="reader" class="reader"></div>
    <p class="scan-hint" id="scan-hint">Starting camera…</p>
    <button class="btn btn-block" id="cam-retry" hidden>Start camera</button>
  </section>

  <section class="scan-pane" id="pane-search" hidden>
    <input id="search-q" type="search" placeholder="Phone, booking ID or name" autocomplete="off" inputmode="search">
    <ul id="search-results" class="results"></ul>
  </section>

  <div class="offline" id="offline" hidden>No internet — scans will fail until you're back online.</div>

  <div class="result" id="result" hidden>
    <div class="result-inner">
      <div class="result-title" id="r-title"></div>
      <div class="result-admit" id="r-admit"></div>
      <div class="result-name" id="r-name"></div>
      <div class="result-sub" id="r-sub"></div>
      <div class="result-hint">Tap to continue</div>
    </div>
  </div>
</main>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="/assets/scan.js?v=<?= e(asset_version('scan.js')) ?>"></script>
<?php render_footer(['nav' => 'staff']);
