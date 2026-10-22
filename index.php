<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$s = settings();
$isGate = ($_GET['from'] ?? '') === 'gate';
$source = $isGate ? 'gate' : 'online';
$salesOpen = $isGate ? $s['gate_sales_open'] === '1' : $s['sales_open'] === '1';
$left = tickets_left();
$soldOut = $left <= 0;
$price = price_paise($source);
$maxQty = max(0, min(max_per_booking(), $left));
$banner = safe_url($s['banner_url']);
$mapSrc = extract_map_src($s['event_map_embed']);
$mapLink = safe_url($s['event_map_link']);
$extraLabel = trim($s['extra_field_label']);
$extraRequired = $s['extra_field_required'] === '1';
$lowStock = !$soldOut && $left <= max(10, (int) (capacity() * 0.1));

render_header($s['event_name'], [
    'description' => $s['event_tagline'] ?: fmt_datetime($s['event_datetime']) . ($s['event_venue'] ? ' · ' . $s['event_venue'] : ''),
    'og_image'    => $banner,
    'body_class'  => $isGate ? 'page-event is-gate' : 'page-event',
]);
?>
<main class="event">
  <?php if (!$isGate): ?>
  <section class="hero<?= $banner ? ' has-banner' : '' ?>">
    <?php if ($banner): ?><img class="hero-img" src="<?= e($banner) ?>" alt=""><?php endif; ?>
    <span class="sparkle" style="top:22%;left:12%" aria-hidden="true">✦</span>
    <span class="sparkle s2" style="top:30%;right:14%" aria-hidden="true">✦</span>
    <span class="sparkle s3" style="bottom:22%;left:22%" aria-hidden="true">✦</span>
    <div class="hero-text wrap">
      <p class="eyebrow"><?= e(fmt_datetime($s['event_datetime'], 'l, j F Y')) ?></p>
      <h1 class="display"><?= e($s['event_name']) ?></h1>
      <?php if ($s['event_tagline']): ?><p class="tagline"><?= e($s['event_tagline']) ?></p><?php endif; ?>
      <?php if ($salesOpen && !$soldOut): ?><p class="hero-cta"><a class="btn btn-primary" href="#book">Buy tickets</a></p><?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="gate-head wrap">
    <p class="eyebrow">Buy at the gate</p>
    <h1 class="display small"><?= e($s['event_name']) ?></h1>
  </section>
  <?php endif; ?>

  <div class="wrap event-grid">
    <?php if (!$isGate): ?>
    <section class="event-info">
      <dl class="facts">
        <div><dt>When</dt><dd><?= e(fmt_datetime($s['event_datetime'])) ?></dd></div>
        <?php if ($s['event_venue']): ?>
        <div><dt>Where</dt><dd><?= e($s['event_venue']) ?><?php if ($mapLink): ?> · <a href="<?= e($mapLink) ?>" target="_blank" rel="noopener">Directions</a><?php endif; ?></dd></div>
        <?php endif; ?>
        <div><dt>Price</dt><dd><?= e(money($price)) ?> <span class="muted">per person</span></dd></div>
      </dl>
      <?php if ($s['event_description']): ?>
      <h2 class="brand-title">What's on</h2>
      <div class="prose"><?= nl2br(e($s['event_description'])) ?></div>
      <?php endif; ?>
      <?php if ($mapSrc): ?>
      <div class="map"><iframe src="<?= e($mapSrc) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Venue map" allowfullscreen></iframe></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <aside class="book" id="book">
      <div class="card book-card">
        <?php if (!$salesOpen): ?>
          <h2>Sales closed</h2>
          <p class="muted"><?= $isGate ? 'Gate sales are not open right now. Please ask at the box office.' : 'Ticket sales are not open right now. Please check back soon.' ?></p>
        <?php elseif ($soldOut): ?>
          <h2>Sold out</h2>
          <p class="muted">All tickets have been sold. Seats held by unfinished payments are released after <?= (int) config('hold_minutes', 15) ?> minutes, so check back shortly.</p>
        <?php else: ?>
          <div class="book-head">
            <h2 class="brand-title" style="margin:0">Tickets</h2>
            <div class="price"><?= e(money($price)) ?><span>/person</span></div>
          </div>
          <?php if ($lowStock): ?><p class="stock">Only <?= $left ?> left</p><?php endif; ?>

          <form id="order-form" novalidate data-price="<?= $price ?>" data-source="<?= e($source) ?>">
            <?= csrf_field() ?>
            <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <label class="field">
              <span>Full name</span>
              <input name="name" required maxlength="120" autocomplete="name">
            </label>
            <label class="field">
              <span>Phone (WhatsApp)</span>
              <input name="phone" required inputmode="tel" autocomplete="tel" placeholder="98765 43210">
            </label>
            <label class="field">
              <span>Email<?= $isGate ? ' <em>(optional)</em>' : '' ?></span>
              <input name="email" type="email" <?= $isGate ? '' : 'required' ?> maxlength="190" autocomplete="email" inputmode="email">
            </label>
            <?php if ($extraLabel !== '' && !$isGate): ?>
            <label class="field">
              <span><?= e($extraLabel) ?><?= $extraRequired ? '' : ' <em>(optional)</em>' ?></span>
              <input name="extra_value" maxlength="255" <?= $extraRequired ? 'required' : '' ?>>
            </label>
            <?php endif; ?>
            <div class="field">
              <span>Tickets</span>
              <div class="stepper" data-max="<?= $maxQty ?>">
                <button type="button" data-step="-1" aria-label="One less">−</button>
                <input name="quantity" type="number" value="1" min="1" max="<?= $maxQty ?>" inputmode="numeric" aria-label="Number of tickets">
                <button type="button" data-step="1" aria-label="One more">+</button>
              </div>
            </div>
            <?php if (!$isGate && $s['terms']): ?>
            <label class="check">
              <input type="checkbox" name="terms" value="1" required>
              <span>I agree to the <a href="#terms">terms</a></span>
            </label>
            <?php else: ?>
            <input type="hidden" name="terms" value="1">
            <?php endif; ?>

            <p class="form-error" role="alert" hidden></p>
            <button class="btn btn-primary btn-block" type="submit">
              <span class="btn-label">Pay <span data-total><?= e(money($price)) ?></span></span>
            </button>
            <p class="fineprint">Secure payment by Razorpay · UPI, cards, netbanking. Your ticket arrives on WhatsApp<?= $isGate ? '' : ' and email' ?>.</p>
          </form>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <?php if (!$isGate && $s['terms']): ?>
  <section class="wrap terms" id="terms">
    <h3>Terms</h3>
    <div class="prose small"><?= nl2br(e($s['terms'])) ?></div>
  </section>
  <?php endif; ?>
</main>
<?php if ($salesOpen && !$soldOut): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js" defer></script>
<script src="/assets/checkout.js?v=<?= e(asset_version('checkout.js')) ?>" defer></script>
<?php endif; ?>
<?php render_footer();
