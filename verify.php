<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/*
 * Step 2 of a purchase: Razorpay Checkout succeeded in the browser.
 * The ticket is issued only if the signature verifies with our key secret.
 */

require_method('POST');
require_csrf();

$in = json_input();
$orderId = (string) ($in['razorpay_order_id'] ?? '');
$paymentId = (string) ($in['razorpay_payment_id'] ?? '');
$signature = (string) ($in['razorpay_signature'] ?? '');

if (!rzp_verify_payment_signature($orderId, $paymentId, $signature)) {
    app_log('warning', 'Rejected checkout signature', ['order' => $orderId, 'ip' => client_ip()]);
    json_error('We could not verify this payment. If money was deducted, your ticket will be sent automatically within a few minutes, or contact us.', 400);
}

$result = issue_ticket_for_order($orderId, $paymentId);
$booking = $result['booking'];

if (!$booking) {
    app_log('error', 'Verified payment for unknown order', ['order' => $orderId, 'payment' => $paymentId]);
    json_error('Booking not found. Please contact us with your payment ID: ' . $paymentId, 404);
}
if ($booking['status'] !== 'paid') {
    json_error('This booking was cancelled. Please contact us.', 409);
}

$response = ['redirect' => link_to('/ticket.php?t=' . $booking['token'] . '&new=1')];

if ($result['issued']) {
    // Respond first, then send email + WhatsApp.
    json_out_then($response, static fn() => deliver_ticket($booking));
}
json_out($response);
