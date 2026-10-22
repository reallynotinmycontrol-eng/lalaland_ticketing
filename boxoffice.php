<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/*
 * Box office: staff sell a ticket for cash or UPI paid to the organiser's own
 * QR (outside Razorpay). Creates a paid booking straight away.
 */

$user = require_role('staff');
$s = settings();
$price = price_paise('boxoffice');
$max = max_per_booking();
$errors = [];
$old = ['name' => '', 'phone' => '', 'email' => '', 'quantity' => 1, 'payment_method' => 'cash'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $action = (string) ($_POST['action'] ?? 'sell');

    if ($action === 'checkin') {
        $res = check_in(['booking_id' => (int) ($_POST['booking_id'] ?? 0)], (int) $user['id']);
        flash($res['result'] === 'allowed'
            ? 'Checked in: ' . $res['name'] . ' (admit ' . $res['quantity'] . ')'
            : 'Not checked in: ' . str_replace('_', ' ', $res['result']), $res['result'] === 'allowed' ? 'ok' : 'error');
        redirect('/boxoffice.php');
    }

    $old = [
        'name'           => trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? ''),
        'phone'          => (string) ($_POST['phone'] ?? ''),
        'email'          => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
        'quantity'       => (int) ($_POST['quantity'] ?? 1),
        'payment_method' => ($_POST['payment_method'] ?? '') === 'upi_direct' ? 'upi_direct' : 'cash',
    ];
    $phone = normalize_phone($old['phone']);
    if (mb_strlen($old['name']) < 2) {
        $errors[] = 'Enter the buyer’s name.';
    }
    if ($phone === null) {
        $errors[] = 'Enter a valid mobile number.';
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email looks wrong (or leave it empty).';
    }
    if ($old['quantity'] < 1 || $old['quantity'] > $max) {
        $errors[] = "Quantity must be 1 to $max.";
    }
    if (empty($_POST['received'])) {
        $errors[] = 'Confirm that you have received the money.';
    }
    if ($price < 100) {
        $errors[] = 'Ticket price is not set up yet (admin → Event).';
    }

    if (!$errors) {
        $qty = $old['quantity'];
        $booking = with_capacity_lock(static function () use ($old, $phone, $qty, $price, $user) {
            if ($qty > tickets_left()) {
                return null;
            }
            return create_booking([
                'name' => $old['name'], 'email' => $old['email'], 'phone' => $phone,
                'quantity' => $qty, 'amount_paise' => $price * $qty, 'source' => 'boxoffice',
                'payment_method' => $old['payment_method'], 'status' => 'paid', 'created_by' => (int) $user['id'],
            ]);
        });
        if ($booking === null) {
            $errors[] = 'Not enough tickets left (only ' . tickets_left() . ').';
        } else {
            app_log('info', 'Box office sale', ['ref' => $booking['ref'], 'by' => $user['id'], 'method' => $booking['payment_method']]);
            start_session();
            $_SESSION['bo_last'] = (int) $booking['id'];
            header('Location: /boxoffice.php?done=1');
            header('Content-Length: 0');
            header('Connection: close');
            finish_response_then(static fn() => deliver_ticket($booking));
            exit;
        }
    }
}

$last = null;
if (!empty($_GET['done'])) {
    start_session();
    $last = isset($_SESSION['bo_last']) ? booking_by_id((int) $_SESSION['bo_last']) : null;
}

// This staff member's box office totals (for cash reconciliation).
$mine = q_row(
    "SELECT COUNT(*) AS bookings, COALESCE(SUM(quantity),0) AS tickets,
            COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paise ELSE 0 END),0) AS cash,
            COALESCE(SUM(CASE WHEN payment_method = 'upi_direct' THEN amount_paise ELSE 0 END),0) AS upi
       FROM tk_bookings WHERE source = 'boxoffice' AND status = 'paid' AND created_by = ?",
    [$user['id']]
);

render_header('Box office', ['nav' => 'staff', 'body_class' => 'page-boxoffice']);
?>
<main class="wrap narrow">
  <?php if ($last): ?>
  <div class="card sold-card">
    <p class="eyebrow">Sold · <?= e($last['ref']) ?></p>
    <h2><?= e($last['name']) ?> — admit <?= (int) $last['quantity'] ?></h2>
    <p class="muted"><?= e(money((int) $last['amount_paise'])) ?> by <?= $last['payment_method'] === 'cash' ? 'cash' : 'UPI (direct)' ?>. Ticket is being sent on WhatsApp<?= $last['email'] ? ' and email' : '' ?>.</p>
    <div class="row-actions">
      <?php if (!$last['checked_in_at']): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="checkin">
        <input type="hidden" name="booking_id" value="<?= (int) $last['id'] ?>">
        <button class="btn btn-primary">Check in now</button>
      </form>
      <?php else: ?>
      <span class="pill ok">Checked in <?= e(fmt_time($last['checked_in_at'])) ?></span>
      <?php endif; ?>
      <a class="btn" href="/ticket.php?t=<?= e($last['token']) ?>" target="_blank">Show ticket</a>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" class="card" autocomplete="off">
    <div class="book-head">
      <h1>Box office sale</h1>
      <div class="price"><?= e(money($price)) ?><span>/person</span></div>
    </div>
    <p class="muted small">Tickets left: <?= tickets_left() ?></p>
    <?php foreach ($errors as $err): ?><p class="form-error"><?= e($err) ?></p><?php endforeach; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="sell">
    <label class="field"><span>Name</span><input name="name" value="<?= e($old['name']) ?>" required maxlength="120"></label>
    <label class="field"><span>Phone (WhatsApp)</span><input name="phone" value="<?= e($old['phone']) ?>" required inputmode="tel"></label>
    <label class="field"><span>Email <em>(optional)</em></span><input name="email" type="email" value="<?= e($old['email']) ?>"></label>
    <label class="field"><span>Tickets</span>
      <select name="quantity" id="bo-qty" data-price="<?= $price ?>">
        <?php for ($i = 1; $i <= $max; $i++): ?>
        <option value="<?= $i ?>" <?= $old['quantity'] === $i ? 'selected' : '' ?>><?= $i ?> — <?= e(money($price * $i)) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <fieldset class="seg">
      <legend>Paid by</legend>
      <label><input type="radio" name="payment_method" value="cash" <?= $old['payment_method'] === 'cash' ? 'checked' : '' ?>> Cash</label>
      <label><input type="radio" name="payment_method" value="upi_direct" <?= $old['payment_method'] === 'upi_direct' ? 'checked' : '' ?>> UPI to our QR</label>
    </fieldset>
    <label class="check"><input type="checkbox" name="received" value="1"> <span>I have received the money</span></label>
    <button class="btn btn-primary btn-block" type="submit">Create paid ticket</button>
  </form>

  <p class="muted small center">Your box office sales: <?= (int) $mine['tickets'] ?> tickets · cash <?= e(money((int) $mine['cash'])) ?> · UPI <?= e(money((int) $mine['upi'])) ?></p>
</main>
<?php render_footer(['nav' => 'staff']);
