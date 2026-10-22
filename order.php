<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/*
 * Step 1 of a purchase: validate, hold seats, create a pending booking and a
 * Razorpay order. Returns what Checkout.js needs. Nothing here issues a ticket.
 */

require_method('POST');
require_csrf();

$in = json_input();
$s = settings();
$source = ($in['source'] ?? '') === 'gate' ? 'gate' : 'online';

// Honeypot: bots fill every field. Pretend nothing happened.
if (trim((string) ($in['website'] ?? '')) !== '') {
    json_error('Please try again.', 400);
}

// Rate limit: 10 orders per IP per 10 minutes.
$bucket = 'order:' . client_ip();
if (rate_count($bucket, 600) >= 10) {
    json_error('Too many attempts from your connection. Please wait a few minutes.', 429);
}
rate_hit($bucket);

$open = $source === 'gate' ? $s['gate_sales_open'] === '1' : $s['sales_open'] === '1';
if (!$open) {
    json_error('Ticket sales are closed right now.', 409);
}

// ---- Validate -----------------------------------------------------------------
$errors = [];
$name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? '');
$email = mb_strtolower(trim((string) ($in['email'] ?? '')));
$phone = normalize_phone((string) ($in['phone'] ?? ''));
$qty = filter_var($in['quantity'] ?? null, FILTER_VALIDATE_INT);
$extra = trim((string) ($in['extra_value'] ?? ''));

if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
    $errors['name'] = 'Please enter your full name.';
}
if ($phone === null) {
    $errors['phone'] = 'Please enter a valid mobile number.';
}
$emailRequired = $source === 'online';
if ($email === '' && $emailRequired) {
    $errors['email'] = 'Please enter your email.';
} elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
    $errors['email'] = 'Please enter a valid email.';
}
$max = max_per_booking();
if ($qty === false || $qty < 1 || $qty > $max) {
    $errors['quantity'] = "You can book 1 to $max tickets at a time.";
}
if ($source === 'online' && trim($s['extra_field_label']) !== '') {
    if ($s['extra_field_required'] === '1' && $extra === '') {
        $errors['extra_value'] = 'Please fill in ' . $s['extra_field_label'] . '.';
    }
    $extra = mb_substr($extra, 0, 255);
} else {
    $extra = '';
}
if ($source === 'online' && trim($s['terms']) !== '' && empty($in['terms'])) {
    $errors['terms'] = 'Please accept the terms.';
}
if ($errors) {
    json_error(reset($errors), 422, ['fields' => $errors]);
}

// ---- Hold seats + create pending booking (serialised) ---------------------------
$amount = price_paise($source) * $qty;
if ($amount < 100) {
    json_error('Ticket price is not set up yet.', 409);
}

$booking = with_capacity_lock(static function () use ($name, $email, $phone, $extra, $qty, $amount, $source) {
    $left = tickets_left();
    if ($qty > $left) {
        return $left <= 0 ? 'sold_out' : $left;
    }
    return create_booking([
        'name' => $name, 'email' => $email, 'phone' => $phone, 'extra_value' => $extra ?: null,
        'quantity' => $qty, 'amount_paise' => $amount, 'source' => $source,
        'payment_method' => 'razorpay', 'status' => 'pending',
    ]);
});

if ($booking === 'sold_out') {
    json_error('Sorry, tickets just sold out.', 409, ['sold_out' => true]);
}
if (is_int($booking)) {
    json_error("Only $booking " . ($booking === 1 ? 'ticket is' : 'tickets are') . ' left. Please lower the quantity.', 409);
}

// ---- Razorpay order -----------------------------------------------------------
try {
    $order = rzp_create_order($amount, $booking['ref'], ['booking_ref' => $booking['ref']]);
} catch (RazorpayError $e) {
    // No order was created, so release the held seats immediately
    // (failed bookings without an order id don't count towards capacity).
    q("UPDATE tk_bookings SET status = 'failed' WHERE id = ?", [$booking['id']]);
    json_error('Payment service unavailable, please try again.', 503);
}

q('UPDATE tk_bookings SET rzp_order_id = ? WHERE id = ?', [$order['id'], $booking['id']]);

json_out([
    'key_id'      => config('razorpay.key_id'),
    'order_id'    => $order['id'],
    'amount'      => $amount,
    'currency'    => 'INR',
    'name'        => $name,
    'email'       => $email,
    'phone'       => $phone,
    'ref'         => $booking['ref'],
    'event_name'  => $s['event_name'],
    'description' => $qty . ' × ticket',
    'brand'       => (string) config('organiser_name'),
    'color'       => accent_color(),
]);
