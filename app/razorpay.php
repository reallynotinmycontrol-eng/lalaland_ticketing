<?php
declare(strict_types=1);

/*
 * Razorpay over plain REST + cURL (no SDK).
 * Docs: https://razorpay.com/docs/api/orders/ and /docs/webhooks/
 */

final class RazorpayError extends RuntimeException {}

/**
 * Create a Razorpay order. Returns the decoded order (id, amount, status...).
 * @throws RazorpayError
 */
function rzp_create_order(int $amountPaise, string $receipt, array $notes = []): array
{
    $payload = [
        'amount'   => $amountPaise,
        'currency' => 'INR',
        'receipt'  => $receipt,
        'notes'    => (object) $notes,
    ];
    return rzp_request('POST', '/v1/orders', $payload);
}

/** Fetch payments of an order (used to recover a payment id if needed). */
function rzp_fetch_order(string $orderId): array
{
    return rzp_request('GET', '/v1/orders/' . rawurlencode($orderId));
}

function rzp_request(string $method, string $path, ?array $body = null): array
{
    $keyId = (string) config('razorpay.key_id');
    $secret = (string) config('razorpay.key_secret');
    if ($keyId === '' || $secret === '' || str_contains($keyId, 'XXXX')) {
        throw new RazorpayError('Razorpay keys are not configured.');
    }

    $base = rtrim((string) config('razorpay.api_base', 'https://api.razorpay.com'), '/');
    $ch = curl_init($base . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $keyId . ':' . $secret,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        app_log('error', 'Razorpay network error', ['path' => $path, 'error' => $err]);
        throw new RazorpayError('Payment service unavailable.');
    }
    $data = json_decode((string) $raw, true);
    if ($status >= 400 || !is_array($data)) {
        app_log('error', 'Razorpay API error', ['path' => $path, 'status' => $status, 'body' => mb_substr((string) $raw, 0, 500)]);
        throw new RazorpayError($data['error']['description'] ?? 'Payment service error.');
    }
    return $data;
}

/** Checkout response signature: HMAC_SHA256(order_id|payment_id, key_secret). */
function rzp_verify_payment_signature(string $orderId, string $paymentId, string $signature): bool
{
    $secret = (string) config('razorpay.key_secret');
    if ($secret === '' || $orderId === '' || $paymentId === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    return hash_equals($expected, $signature);
}

/** Webhook signature: HMAC_SHA256(raw_body, webhook_secret). */
function rzp_verify_webhook_signature(string $rawBody, string $signature): bool
{
    $secret = (string) config('razorpay.webhook_secret');
    if ($secret === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}
