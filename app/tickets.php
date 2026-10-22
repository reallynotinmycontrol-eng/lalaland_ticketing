<?php
declare(strict_types=1);

/*
 * Booking lifecycle: create, issue (exactly once), check in (exactly once),
 * cancel, undo, search, stats.
 */

const BOOKING_SOURCES = ['online', 'gate', 'boxoffice'];
const BOOKING_STATUSES = ['pending', 'paid', 'failed', 'cancelled'];

/**
 * Insert a booking. Ref and token are generated here.
 * @param array{name:string,email?:string,phone:string,extra_value?:?string,quantity:int,amount_paise:int,source:string,payment_method:string,status:string,created_by?:?int} $b
 */
function create_booking(array $b): array
{
    $now = now();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $ref = new_ref();
        $token = new_token();
        try {
            q(
                'INSERT INTO tk_bookings
                    (ref, token, name, email, phone, extra_value, quantity, amount_paise, source,
                     payment_method, status, created_at, paid_at, created_by, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $ref, $token, $b['name'], $b['email'] ?? '', $b['phone'], $b['extra_value'] ?? null,
                    $b['quantity'], $b['amount_paise'], $b['source'], $b['payment_method'], $b['status'],
                    $now, $b['status'] === 'paid' ? $now : null, $b['created_by'] ?? null, client_ip(),
                ]
            );
            return booking_by_id((int) db()->lastInsertId());
        } catch (PDOException $e) {
            // Retry only on a (very unlikely) ref/token collision.
            if (!in_array($e->getCode(), ['23000', '23505'], true) && !str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique booking reference.');
}

function booking_by_id(int $id): ?array
{
    return q_row('SELECT * FROM tk_bookings WHERE id = ?', [$id]);
}

function booking_by_token(string $token): ?array
{
    return is_token($token) ? q_row('SELECT * FROM tk_bookings WHERE token = ?', [$token]) : null;
}

function booking_by_order(string $orderId): ?array
{
    return q_row('SELECT * FROM tk_bookings WHERE rzp_order_id = ?', [$orderId]);
}

function ticket_url(array $booking): string
{
    return url('/ticket.php?t=' . $booking['token']);
}

function qr_url(array $booking): string
{
    return url('/qr.php?t=' . $booking['token']);
}

/**
 * Mark a Razorpay order paid. Runs as ONE atomic UPDATE so verify.php and
 * webhook.php can both arrive without issuing the ticket twice.
 *
 * @return array{issued:bool, booking:?array} issued=true only for the request
 *         that actually flipped the status (that request sends notifications).
 */
function issue_ticket_for_order(string $orderId, string $paymentId): array
{
    $changed = q(
        "UPDATE tk_bookings
            SET status = 'paid', paid_at = ?, rzp_payment_id = ?
          WHERE rzp_order_id = ? AND status IN ('pending', 'failed')",
        [now(), $paymentId, $orderId]
    )->rowCount();

    $booking = booking_by_order($orderId);
    if ($changed === 1) {
        app_log('info', 'Ticket issued', ['ref' => $booking['ref'] ?? '?', 'order' => $orderId]);
    } elseif ($booking && $booking['status'] === 'cancelled') {
        app_log('warning', 'Payment received for a cancelled booking — refund manually', ['ref' => $booking['ref'], 'payment' => $paymentId]);
    }
    return ['issued' => $changed === 1, 'booking' => $booking];
}

function mark_order_failed(string $orderId): void
{
    q("UPDATE tk_bookings SET status = 'failed' WHERE rzp_order_id = ? AND status = 'pending'", [$orderId]);
}

// ---- Check-in -----------------------------------------------------------------

/**
 * One-time entry. The atomic UPDATE is the only thing that decides "allowed".
 * @param array{token?:string, booking_id?:int} $key
 */
function check_in(array $key, int $staffId): array
{
    $now = now();
    $scanned = '';
    if (isset($key['booking_id'])) {
        $scanned = 'id:' . (int) $key['booking_id'];
        $changed = q(
            "UPDATE tk_bookings SET checked_in_at = ?, checked_in_by = ?
              WHERE id = ? AND status = 'paid' AND checked_in_at IS NULL",
            [$now, $staffId, (int) $key['booking_id']]
        )->rowCount();
        $booking = booking_by_id((int) $key['booking_id']);
    } else {
        $token = (string) ($key['token'] ?? '');
        $scanned = mb_substr($token, 0, 255);
        $changed = 0;
        $booking = null;
        if (is_token($token)) {
            $changed = q(
                "UPDATE tk_bookings SET checked_in_at = ?, checked_in_by = ?
                  WHERE token = ? AND status = 'paid' AND checked_in_at IS NULL",
                [$now, $staffId, $token]
            )->rowCount();
            $booking = booking_by_token($token);
        }
    }

    if ($changed === 1) {
        $result = 'allowed';
    } elseif (!$booking) {
        $result = 'invalid';
    } elseif ($booking['status'] === 'cancelled') {
        $result = 'cancelled';
    } elseif ($booking['status'] !== 'paid') {
        $result = 'not_paid';
    } else {
        $result = 'already_used';
    }

    q(
        'INSERT INTO tk_scan_log (booking_id, scanned_value, result, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
        [$booking['id'] ?? null, $scanned, $result, $staffId, $now]
    );

    $out = ['result' => $result];
    if ($booking) {
        $out += [
            'booking_id'    => (int) $booking['id'],
            'name'          => $booking['name'],
            'quantity'      => (int) $booking['quantity'],
            'ref'           => $booking['ref'],
            'source'        => $booking['source'],
            'checked_in_at' => $booking['checked_in_at'],
            'checked_in_time' => fmt_time($booking['checked_in_at']),
            'checked_in_by' => $booking['checked_in_by']
                ? (string) q_val('SELECT name FROM tk_users WHERE id = ?', [$booking['checked_in_by']])
                : null,
        ];
    }
    return $out;
}

function undo_check_in(int $bookingId, int $adminId): bool
{
    $changed = q(
        'UPDATE tk_bookings SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ? AND checked_in_at IS NOT NULL',
        [$bookingId]
    )->rowCount();
    if ($changed) {
        q(
            "INSERT INTO tk_scan_log (booking_id, scanned_value, result, user_id, created_at) VALUES (?, 'admin', 'undo', ?, ?)",
            [$bookingId, $adminId, now()]
        );
    }
    return $changed === 1;
}

function cancel_booking(int $bookingId, int $adminId): bool
{
    $changed = q(
        "UPDATE tk_bookings SET status = 'cancelled', cancelled_at = ? WHERE id = ? AND status <> 'cancelled'",
        [now(), $bookingId]
    )->rowCount();
    if ($changed) {
        app_log('info', 'Booking cancelled', ['id' => $bookingId, 'by' => $adminId]);
    }
    return $changed === 1;
}

// ---- Search & stats -----------------------------------------------------------

/** Up to $limit paid bookings matching phone digits, ref or name. */
function search_bookings(string $term, int $limit = 20, bool $paidOnly = true): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) {
        return [];
    }
    $where = [];
    $params = [];
    $digits = preg_replace('/\D+/', '', $term) ?? '';
    if (strlen($digits) >= 4) {
        $where[] = 'phone LIKE ?';
        $params[] = '%' . $digits . '%';
    }
    $where[] = 'ref LIKE ?';
    $params[] = '%' . strtoupper($term) . '%';
    $where[] = 'LOWER(name) LIKE ?';
    $params[] = '%' . mb_strtolower($term) . '%';
    $where[] = 'LOWER(email) LIKE ?';
    $params[] = '%' . mb_strtolower($term) . '%';

    $sql = 'SELECT * FROM tk_bookings WHERE (' . implode(' OR ', $where) . ')'
        . ($paidOnly ? " AND status = 'paid'" : '')
        . ' ORDER BY id DESC LIMIT ' . (int) $limit;
    return q($sql, $params)->fetchAll();
}

/** Headline counts for the scanner header and dashboard. */
function gate_counts(): array
{
    $row = q_row(
        "SELECT COALESCE(SUM(quantity), 0) AS sold,
                COALESCE(SUM(CASE WHEN checked_in_at IS NOT NULL THEN quantity ELSE 0 END), 0) AS entered
           FROM tk_bookings WHERE status = 'paid'"
    );
    return ['sold' => (int) $row['sold'], 'entered' => (int) $row['entered']];
}

// ---- After-response work --------------------------------------------------------

/**
 * Finish the HTTP response now, then keep running (for sending email/WhatsApp
 * without making the buyer wait). Falls back to running inline.
 */
function finish_response_then(callable $work): void
{
    ignore_user_abort(true);
    set_time_limit(60);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
    try {
        $work();
    } catch (Throwable $e) {
        app_log('error', 'After-response task failed: ' . $e->getMessage());
    }
}

/** Send JSON, then run $work after the client has the response. */
function json_out_then(array $data, callable $work): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    finish_response_then($work);
    exit;
}
