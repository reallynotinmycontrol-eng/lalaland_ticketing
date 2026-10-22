<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

require_role('admin');
$s = settings();
$counts = gate_counts();
$cap = capacity();

$revenue = (int) q_val("SELECT COALESCE(SUM(amount_paise),0) FROM tk_bookings WHERE status = 'paid'");
$bySource = [];
foreach (q("SELECT source, COUNT(*) AS bookings, COALESCE(SUM(quantity),0) AS tickets, COALESCE(SUM(amount_paise),0) AS amount
              FROM tk_bookings WHERE status = 'paid' GROUP BY source")->fetchAll() as $r) {
    $bySource[$r['source']] = $r;
}
$cashByStaff = q(
    "SELECT u.name, COUNT(b.id) AS bookings, COALESCE(SUM(b.quantity),0) AS tickets,
            COALESCE(SUM(CASE WHEN b.payment_method = 'cash' THEN b.amount_paise ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN b.payment_method = 'upi_direct' THEN b.amount_paise ELSE 0 END),0) AS upi
       FROM tk_bookings b LEFT JOIN tk_users u ON u.id = b.created_by
      WHERE b.source = 'boxoffice' AND b.status = 'paid'
      GROUP BY b.created_by, u.name ORDER BY cash DESC"
)->fetchAll();
$latest = q("SELECT * FROM tk_bookings WHERE status = 'paid' ORDER BY paid_at DESC, id DESC LIMIT 10")->fetchAll();
$failures = q(
    "SELECT * FROM tk_bookings WHERE status = 'paid'
        AND (email_status LIKE 'Error%' OR wa_status LIKE 'Error%')
      ORDER BY id DESC LIMIT 20"
)->fetchAll();
$pendingNow = (int) q_val("SELECT COALESCE(SUM(quantity),0) FROM tk_bookings WHERE status = 'pending' AND created_at > ?", [now('-' . (int) config('hold_minutes', 15) . ' minutes')]);
$isTestMode = str_starts_with((string) config('razorpay.key_id'), 'rzp_test_');

render_header('Dashboard', ['nav' => 'admin', 'body_class' => 'page-admin']);
?>
<main class="wrap admin">
  <div class="admin-head">
    <div>
      <h1><?= e($s['event_name']) ?></h1>
      <p class="muted"><?= e(fmt_datetime($s['event_datetime'])) ?><?= $s['event_venue'] ? ' · ' . e($s['event_venue']) : '' ?></p>
    </div>
    <div class="switches">
      <span class="pill <?= $s['sales_open'] === '1' ? 'ok' : 'off' ?>">Online sales <?= $s['sales_open'] === '1' ? 'open' : 'closed' ?></span>
      <span class="pill <?= $s['gate_sales_open'] === '1' ? 'ok' : 'off' ?>">Gate sales <?= $s['gate_sales_open'] === '1' ? 'open' : 'closed' ?></span>
      <?php if ($isTestMode): ?><span class="pill warn">Razorpay test mode</span><?php endif; ?>
      <?php if (config('debug')): ?><span class="pill warn">Debug on</span><?php endif; ?>
    </div>
  </div>

  <section class="stats">
    <div class="stat">
      <span>Tickets sold</span>
      <strong><?= indian_number($counts['sold']) ?><small> / <?= indian_number($cap) ?></small></strong>
      <div class="bar"><i style="width:<?= $cap ? min(100, round($counts['sold'] / $cap * 100)) : 0 ?>%"></i></div>
      <?php if ($pendingNow): ?><em class="muted small"><?= $pendingNow ?> held in checkout</em><?php endif; ?>
    </div>
    <div class="stat"><span>Revenue (paid)</span><strong><?= e(money($revenue)) ?></strong></div>
    <div class="stat">
      <span>Entered</span>
      <strong><?= indian_number($counts['entered']) ?><small> / <?= indian_number($counts['sold']) ?></small></strong>
      <div class="bar"><i style="width:<?= $counts['sold'] ? round($counts['entered'] / $counts['sold'] * 100) : 0 ?>%"></i></div>
    </div>
  </section>

  <section class="grid-2">
    <div class="card">
      <h2>Sales by channel</h2>
      <table class="table">
        <thead><tr><th>Channel</th><th class="num">Bookings</th><th class="num">Tickets</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php foreach (['online' => 'Online', 'gate' => 'Gate (poster)', 'boxoffice' => 'Box office'] as $k => $label): $r = $bySource[$k] ?? ['bookings' => 0, 'tickets' => 0, 'amount' => 0]; ?>
          <tr><td><?= $label ?></td><td class="num"><?= (int) $r['bookings'] ?></td><td class="num"><?= (int) $r['tickets'] ?></td><td class="num"><?= e(money((int) $r['amount'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>Box office by staff</h2>
      <?php if (!$cashByStaff): ?><p class="muted">No box office sales yet.</p><?php else: ?>
      <table class="table">
        <thead><tr><th>Staff</th><th class="num">Tickets</th><th class="num">Cash</th><th class="num">UPI</th></tr></thead>
        <tbody>
        <?php foreach ($cashByStaff as $r): ?>
          <tr><td><?= e($r['name'] ?? 'Unknown') ?></td><td class="num"><?= (int) $r['tickets'] ?></td><td class="num"><?= e(money((int) $r['cash'])) ?></td><td class="num"><?= e(money((int) $r['upi'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($failures): ?>
  <section class="card attention">
    <h2>Needs attention: delivery failed</h2>
    <table class="table">
      <thead><tr><th>Booking</th><th>Name</th><th>Email</th><th>WhatsApp</th></tr></thead>
      <tbody>
      <?php foreach ($failures as $b): ?>
        <tr>
          <td><a href="/admin/bookings.php?id=<?= (int) $b['id'] ?>" class="mono"><?= e($b['ref']) ?></a></td>
          <td><?= e($b['name']) ?></td>
          <td class="small <?= str_starts_with((string) $b['email_status'], 'Error') ? 'bad' : '' ?>"><?= e($b['email_status'] ?? '—') ?></td>
          <td class="small <?= str_starts_with((string) $b['wa_status'], 'Error') ? 'bad' : '' ?>"><?= e($b['wa_status'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Latest bookings</h2><a href="/admin/bookings.php">All bookings →</a></div>
    <?php if (!$latest): ?><p class="muted">No paid bookings yet.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Booking</th><th>Name</th><th class="num">Qty</th><th class="num">Amount</th><th>Channel</th><th>Paid</th></tr></thead>
      <tbody>
      <?php foreach ($latest as $b): ?>
        <tr>
          <td><a class="mono" href="/admin/bookings.php?id=<?= (int) $b['id'] ?>"><?= e($b['ref']) ?></a></td>
          <td><?= e($b['name']) ?></td>
          <td class="num"><?= (int) $b['quantity'] ?></td>
          <td class="num"><?= e(money((int) $b['amount_paise'])) ?></td>
          <td><?= e($b['source']) ?></td>
          <td class="small muted"><?= e(fmt_datetime($b['paid_at'], 'j M, g:i A')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="quick">
    <a class="btn" href="/" target="_blank">View event page</a>
    <a class="btn" href="/admin/gate-poster.php" target="_blank">Gate poster</a>
    <a class="btn" href="/admin/export.php">Export CSV</a>
  </section>
</main>
<?php render_footer(['nav' => 'admin']);
