<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$admin = require_role('admin');

// ---- Actions ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $id = (int) ($_POST['id'] ?? 0);
    $b = booking_by_id($id);
    if (!$b) {
        flash('Booking not found.', 'error');
        redirect('/admin/bookings.php');
    }
    switch ($_POST['action'] ?? '') {
        case 'resend_email':
        case 'resend_whatsapp':
        case 'resend_both':
            if ($b['status'] !== 'paid') {
                flash('Only paid bookings can be resent.', 'error');
                break;
            }
            $channels = match ($_POST['action']) {
                'resend_email' => ['email'],
                'resend_whatsapp' => ['whatsapp'],
                default => ['email', 'whatsapp'],
            };
            $res = deliver_ticket($b, $channels);
            $parts = [];
            foreach ($res as $ch => $r) {
                $parts[] = ucfirst($ch) . ': ' . $r;
            }
            $anyError = (bool) array_filter($res, static fn($r) => str_starts_with($r, 'Error'));
            flash(implode(' · ', $parts), $anyError ? 'error' : 'ok');
            break;
        case 'cancel':
            flash(cancel_booking($id, (int) $admin['id'])
                ? 'Booking cancelled. Refund it in the Razorpay dashboard if it was paid online.'
                : 'Booking was already cancelled.', 'ok');
            break;
        case 'undo_checkin':
            flash(undo_check_in($id, (int) $admin['id']) ? 'Check-in undone. The ticket can be scanned again.' : 'Booking was not checked in.', 'ok');
            break;
        case 'mark_checked_in':
            $r = check_in(['booking_id' => $id], (int) $admin['id']);
            flash($r['result'] === 'allowed' ? 'Checked in.' : 'Not checked in: ' . str_replace('_', ' ', $r['result']), $r['result'] === 'allowed' ? 'ok' : 'error');
            break;
    }
    redirect('/admin/bookings.php?id=' . $id);
}

// ---- Detail view ----------------------------------------------------------------
if (isset($_GET['id'])) {
    $b = booking_by_id((int) $_GET['id']);
    if (!$b) {
        http_response_code(404);
        flash('Booking not found.', 'error');
        redirect('/admin/bookings.php');
    }
    $userName = static fn($id) => $id ? (string) q_val('SELECT name FROM tk_users WHERE id = ?', [$id]) : '';
    $log = q(
        'SELECT l.*, u.name AS staff FROM tk_scan_log l LEFT JOIN tk_users u ON u.id = l.user_id
          WHERE l.booking_id = ? ORDER BY l.id DESC LIMIT 100',
        [$b['id']]
    )->fetchAll();
    $label = setting('extra_field_label');

    render_header($b['ref'], ['nav' => 'admin', 'body_class' => 'page-admin']);
    ?>
<main class="wrap admin">
  <p><a href="/admin/bookings.php">← All bookings</a></p>
  <div class="admin-head">
    <div>
      <h1 class="mono"><?= e($b['ref']) ?></h1>
      <p class="muted"><?= e($b['name']) ?> · admit <?= (int) $b['quantity'] ?></p>
    </div>
    <div class="switches">
      <span class="pill status-<?= e($b['status']) ?>"><?= e($b['status']) ?></span>
      <?php if ($b['checked_in_at']): ?><span class="pill ok">Entered <?= e(fmt_time($b['checked_in_at'])) ?></span><?php endif; ?>
    </div>
  </div>

  <section class="grid-2">
    <div class="card">
      <h2>Details</h2>
      <dl class="kv">
        <dt>Name</dt><dd><?= e($b['name']) ?></dd>
        <dt>Phone</dt><dd><a href="tel:+<?= e($b['phone']) ?>"><?= e(pretty_phone($b['phone'])) ?></a> · <a href="https://wa.me/<?= e($b['phone']) ?>" target="_blank" rel="noopener">WhatsApp</a></dd>
        <dt>Email</dt><dd><?= $b['email'] ? e($b['email']) : '<span class="muted">—</span>' ?></dd>
        <?php if ($b['extra_value'] !== null && $b['extra_value'] !== ''): ?><dt><?= e($label ?: 'Extra') ?></dt><dd><?= e($b['extra_value']) ?></dd><?php endif; ?>
        <dt>Tickets</dt><dd><?= (int) $b['quantity'] ?></dd>
        <dt>Amount</dt><dd><?= e(money((int) $b['amount_paise'])) ?></dd>
        <dt>Channel</dt><dd><?= e($b['source']) ?> · <?= e($b['payment_method']) ?><?= $b['created_by'] ? ' · sold by ' . e($userName($b['created_by'])) : '' ?></dd>
        <dt>Created</dt><dd><?= e(fmt_datetime($b['created_at'])) ?></dd>
        <dt>Paid</dt><dd><?= $b['paid_at'] ? e(fmt_datetime($b['paid_at'])) : '<span class="muted">—</span>' ?></dd>
        <?php if ($b['rzp_order_id']): ?><dt>Razorpay</dt><dd class="mono small"><?= e($b['rzp_order_id']) ?><br><?= e($b['rzp_payment_id'] ?? '') ?></dd><?php endif; ?>
        <dt>Entered</dt><dd><?= $b['checked_in_at'] ? e(fmt_datetime($b['checked_in_at'])) . ' by ' . e($userName($b['checked_in_by'])) : '<span class="muted">Not yet</span>' ?></dd>
        <dt>Email</dt><dd class="small <?= str_starts_with((string) $b['email_status'], 'Error') ? 'bad' : '' ?>"><?= e($b['email_status'] ?? '—') ?></dd>
        <dt>WhatsApp</dt><dd class="small <?= str_starts_with((string) $b['wa_status'], 'Error') ? 'bad' : '' ?>"><?= e($b['wa_status'] ?? '—') ?></dd>
      </dl>
    </div>

    <div class="card">
      <h2>Actions</h2>
      <div class="actions">
        <a class="btn" href="/ticket.php?t=<?= e($b['token']) ?>" target="_blank">Open ticket page</a>
        <?php if ($b['status'] === 'paid'): ?>
          <?php foreach (['resend_both' => 'Resend email + WhatsApp', 'resend_email' => 'Resend email', 'resend_whatsapp' => 'Resend WhatsApp'] as $act => $lbl): ?>
          <form method="post" data-confirm="<?= e($lbl) ?> to <?= e($b['name']) ?>?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="<?= $act ?>">
            <button class="btn"><?= e($lbl) ?></button>
          </form>
          <?php endforeach; ?>
          <?php if ($b['checked_in_at']): ?>
          <form method="post" data-confirm="Undo check-in? The ticket will be accepted at the gate again.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="undo_checkin">
            <button class="btn">Undo check-in</button>
          </form>
          <?php else: ?>
          <form method="post" data-confirm="Mark as entered now?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="mark_checked_in">
            <button class="btn">Mark as entered</button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($b['status'] !== 'cancelled'): ?>
          <form method="post" data-confirm="Cancel <?= e($b['ref']) ?>? The QR will be rejected at the gate. Refund manually in Razorpay.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="cancel">
            <button class="btn btn-danger">Cancel booking</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card">
    <h2>Scan history</h2>
    <?php if (!$log): ?><p class="muted">No scans yet.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Time</th><th>Result</th><th>Staff</th></tr></thead>
      <tbody>
      <?php foreach ($log as $l): ?>
        <tr><td><?= e(fmt_datetime($l['created_at'], 'j M, g:i:s A')) ?></td><td><span class="pill res-<?= e($l['result']) ?>"><?= e(str_replace('_', ' ', $l['result'])) ?></span></td><td><?= e($l['staff'] ?? '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
<?php
    render_footer(['nav' => 'admin']);
    exit;
}

// ---- List view ------------------------------------------------------------------
$qTerm = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', BOOKING_STATUSES, true) ? $_GET['status'] : '';
$source = in_array($_GET['source'] ?? '', BOOKING_SOURCES, true) ? $_GET['source'] : '';
$entered = in_array($_GET['entered'] ?? '', ['yes', 'no'], true) ? $_GET['entered'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;

$where = [];
$params = [];
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
} else {
    $where[] = "status <> 'pending'"; // hide abandoned checkouts by default
}
if ($source !== '') {
    $where[] = 'source = ?';
    $params[] = $source;
}
if ($entered === 'yes') {
    $where[] = 'checked_in_at IS NOT NULL';
} elseif ($entered === 'no') {
    $where[] = 'checked_in_at IS NULL';
}
if ($qTerm !== '') {
    $sub = ['ref LIKE ?', 'LOWER(name) LIKE ?', 'LOWER(email) LIKE ?'];
    $params[] = '%' . strtoupper($qTerm) . '%';
    $params[] = '%' . mb_strtolower($qTerm) . '%';
    $params[] = '%' . mb_strtolower($qTerm) . '%';
    $digits = preg_replace('/\D+/', '', $qTerm) ?? '';
    if (strlen($digits) >= 4) {
        $sub[] = 'phone LIKE ?';
        $params[] = '%' . $digits . '%';
    }
    $where[] = '(' . implode(' OR ', $sub) . ')';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val("SELECT COUNT(*) FROM tk_bookings $whereSql", $params);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = q("SELECT * FROM tk_bookings $whereSql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();

$qs = static fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(
    ['q' => $qTerm, 'status' => $status, 'source' => $source, 'entered' => $entered, 'page' => $page],
    $over
), static fn($v) => $v !== null && $v !== '' && $v !== 1));

render_header('Bookings', ['nav' => 'admin', 'body_class' => 'page-admin']);
?>
<main class="wrap admin">
  <div class="admin-head">
    <h1>Bookings <span class="muted small"><?= indian_number($total) ?></span></h1>
    <a class="btn" href="/admin/export.php">Export CSV</a>
  </div>

  <form class="filters card" method="get">
    <input type="search" name="q" value="<?= e($qTerm) ?>" placeholder="Name, phone, email or booking ID">
    <select name="status">
      <option value="">Paid, failed, cancelled</option>
      <?php foreach (BOOKING_STATUSES as $st): ?><option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
    </select>
    <select name="source">
      <option value="">All channels</option>
      <?php foreach (BOOKING_SOURCES as $src): ?><option value="<?= $src ?>" <?= $source === $src ? 'selected' : '' ?>><?= $src === 'boxoffice' ? 'Box office' : ucfirst($src) ?></option><?php endforeach; ?>
    </select>
    <select name="entered">
      <option value="">Entered: any</option>
      <option value="yes" <?= $entered === 'yes' ? 'selected' : '' ?>>Entered</option>
      <option value="no" <?= $entered === 'no' ? 'selected' : '' ?>>Not entered</option>
    </select>
    <button class="btn btn-primary">Filter</button>
  </form>

  <div class="card table-scroll">
    <?php if (!$rows): ?><p class="muted">No bookings match.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Booking</th><th>Name</th><th>Phone</th><th>Email</th><th class="num">Qty</th><th class="num">Amount</th><th>Channel</th><th>Status</th><th>Entered</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $b): ?>
        <tr>
          <td><a class="mono" href="/admin/bookings.php?id=<?= (int) $b['id'] ?>"><?= e($b['ref']) ?></a></td>
          <td><?= e($b['name']) ?></td>
          <td class="small"><?= e(pretty_phone($b['phone'])) ?></td>
          <td class="small"><?= e($b['email']) ?></td>
          <td class="num"><?= (int) $b['quantity'] ?></td>
          <td class="num"><?= e(money((int) $b['amount_paise'])) ?></td>
          <td class="small"><?= e($b['source']) ?></td>
          <td><span class="pill status-<?= e($b['status']) ?>"><?= e($b['status']) ?></span></td>
          <td class="small"><?= $b['checked_in_at'] ? e(fmt_datetime($b['checked_in_at'], 'j M, g:i A')) : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a class="btn" href="<?= e($qs(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn" href="<?= e($qs(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</main>
<?php render_footer(['nav' => 'admin']);
