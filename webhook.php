<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/*
 * Razorpay webhook (backup path). Subscribed events: order.paid, payment.failed.
 * Authenticated by X-Razorpay-Signature over the raw body, not by CSRF.
 * Always answer 2xx quickly for events we've handled or ignore, so Razorpay
 * doesn't keep retrying; answer 4xx only for bad signatures.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$sig = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (!rzp_verify_webhook_signature($raw, $sig)) {
    app_log('warning', 'Webhook signature rejected', ['ip' => client_ip()]);
    http_response_code(400);
    exit('bad signature');
}

$event = json_decode($raw, true);
$type = (string) ($event['event'] ?? '');
$payment = $event['payload']['payment']['entity'] ?? [];
$order = $event['payload']['order']['entity'] ?? [];

switch ($type) {
    case 'order.paid':
        $orderId = (string) ($order['id'] ?? $payment['order_id'] ?? '');
        $paymentId = (string) ($payment['id'] ?? '');
        $paidAmount = (int) ($order['amount_paid'] ?? $payment['amount'] ?? 0);
        $booking = $orderId !== '' ? booking_by_order($orderId) : null;

        if (!$booking) {
            app_log('warning', 'Webhook for unknown order', ['order' => $orderId]);
            json_out(['ok' => true, 'note' => 'unknown order']);
        }
        if ($paidAmount !== (int) $booking['amount_paise']) {
            app_log('error', 'Webhook amount mismatch', ['ref' => $booking['ref'], 'paid' => $paidAmount, 'expected' => $booking['amount_paise']]);
            json_out(['ok' => true, 'note' => 'amount mismatch']);
        }

        $result = issue_ticket_for_order($orderId, $paymentId);
        if ($result['issued'] && $result['booking']) {
            $issued = $result['booking'];
            json_out_then(['ok' => true, 'issued' => true], static fn() => deliver_ticket($issued));
        }
        json_out(['ok' => true, 'issued' => false]);

    case 'payment.failed':
        $orderId = (string) ($payment['order_id'] ?? '');
        if ($orderId !== '') {
            mark_order_failed($orderId);
        }
        json_out(['ok' => true]);

    default:
        json_out(['ok' => true, 'ignored' => $type]);
}
