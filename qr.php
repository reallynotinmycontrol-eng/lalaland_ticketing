<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/*
 * QR PNG for a ticket, by secret token. Used by the ticket page, the email
 * fallback link and the WhatsApp template header (must be public HTTPS).
 * The QR encodes the ticket URL; the scanner extracts the token from it.
 */

$token = (string) ($_GET['t'] ?? '');
$b = booking_by_token($token);
if (!$b) {
    http_response_code(404);
    exit;
}

$etag = '"' . substr(hash('sha256', $token . 'v1'), 0, 16) . '"';
header('Cache-Control: public, max-age=604800, immutable');
header('ETag: ' . $etag);
header('X-Robots-Tag: noindex');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$size = (int) ($_GET['s'] ?? 10);
$size = max(4, min(16, $size));

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="ticket-' . $b['ref'] . '.png"');
echo qr_png(ticket_url($b), $size);
