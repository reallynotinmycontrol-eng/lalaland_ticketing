<?php
declare(strict_types=1);

// ---- Output -------------------------------------------------------------------

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('base_url'), '/') . '/' . ltrim($path, '/');
}

/** Relative link inside the app (works on any host, incl. local dev). */
function link_to(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('~^https?://~', $path) ? $path : link_to($path)));
    exit;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_out(['error' => $message] + $extra, $status);
}

/** Decoded JSON request body. */
function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_error('Method not allowed', 405);
    }
}

function client_ip(): string
{
    // Hostinger passes the real visitor IP in REMOTE_ADDR.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// ---- CSRF ---------------------------------------------------------------------

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    start_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function require_csrf(bool $json = true): void
{
    if (!csrf_valid()) {
        if ($json) {
            json_error('Your session expired. Please reload the page and try again.', 419);
        }
        http_response_code(419);
        exit('Your session expired. Please go back, reload the page and try again.');
    }
}

// ---- Flash messages -----------------------------------------------------------

function flash(string $message, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = ['m' => $message, 't' => $type];
}

function take_flashes(): array
{
    start_session();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- Money, dates, phone ------------------------------------------------------

function rupees_to_paise(string|int|float $rupees): int
{
    return (int) round(((float) $rupees) * 100);
}

function money(int $paise): string
{
    $r = $paise / 100;
    $decimals = ($paise % 100) === 0 ? 0 : 2;
    return '₹' . indian_number($r, $decimals);
}

/** 1234567 -> 12,34,567 */
function indian_number(float $n, int $decimals = 0): string
{
    $neg = $n < 0;
    $s = number_format(abs($n), $decimals, '.', '');
    [$int, $frac] = array_pad(explode('.', $s), 2, '');
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
}

function fmt_datetime(?string $dt, string $format = 'D, j M Y · g:i A'): string
{
    if (!$dt) {
        return '';
    }
    try {
        return (new DateTimeImmutable($dt))->format($format);
    } catch (Exception) {
        return $dt;
    }
}

function fmt_time(?string $dt): string
{
    return fmt_datetime($dt, 'g:i A');
}

/**
 * Normalise a phone number to digits with country code.
 * Returns null if invalid. Indian numbers must match ^91[6-9]\d{9}$.
 */
function normalize_phone(string $input): ?string
{
    $digits = preg_replace('/\D+/', '', $input) ?? '';
    $cc = (string) config('default_country_code', '91');
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1); // 09876543210
    }
    if (strlen($digits) === 10) {
        $digits = $cc . $digits;
    }
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '91')) {
        return preg_match('/^91[6-9]\d{9}$/', $digits) ? $digits : null;
    }
    return preg_match('/^[1-9]\d{7,14}$/', $digits) ? $digits : null;
}

function mask_phone(string $phone): string
{
    return strlen($phone) > 4 ? str_repeat('•', max(0, strlen($phone) - 6)) . substr($phone, -4) : $phone;
}

function pretty_phone(string $phone): string
{
    if (preg_match('/^91(\d{5})(\d{5})$/', $phone, $m)) {
        return '+91 ' . $m[1] . ' ' . $m[2];
    }
    return '+' . $phone;
}

// ---- Codes --------------------------------------------------------------------

/** Human booking ref like TK-7F3K2QA (no 0/O/1/I). */
function new_ref(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 7; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'TK-' . $s;
}

/** 128-bit secret ticket token. */
function new_token(): string
{
    return bin2hex(random_bytes(16));
}

function is_token(string $s): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $s);
}

// ---- Rate limiting (DB-backed) ------------------------------------------------

function rate_count(string $bucket, int $windowSeconds): int
{
    return (int) q_val(
        'SELECT COUNT(*) FROM tk_attempts WHERE bucket = ? AND created_at > ?',
        [$bucket, now('-' . $windowSeconds . ' seconds')]
    );
}

function rate_hit(string $bucket): void
{
    q('INSERT INTO tk_attempts (bucket, created_at) VALUES (?, ?)', [$bucket, now()]);
    // Light housekeeping (~1% of hits): drop entries older than a day.
    if (random_int(1, 100) === 1) {
        q('DELETE FROM tk_attempts WHERE created_at < ?', [now('-1 day')]);
    }
}

function rate_clear(string $bucket): void
{
    q('DELETE FROM tk_attempts WHERE bucket = ?', [$bucket]);
}

// ---- Event / capacity ---------------------------------------------------------

function capacity(): int
{
    return max(0, (int) setting('capacity', '0'));
}

function max_per_booking(): int
{
    return max(1, (int) setting('max_per_booking', '5'));
}

/** Seats sold (paid) plus seats held by unpaid checkouts started recently. */
function tickets_taken(): int
{
    $holdFrom = now('-' . (int) config('hold_minutes', 15) . ' minutes');
    return (int) q_val(
        "SELECT COALESCE(SUM(quantity), 0) FROM tk_bookings
         WHERE status = 'paid'
            OR (status = 'pending' AND created_at > ?)
            OR (status = 'failed' AND rzp_order_id IS NOT NULL AND created_at > ?)",
        [$holdFrom, $holdFrom]
    );
}

function tickets_left(): int
{
    return max(0, capacity() - tickets_taken());
}

function price_paise(string $source = 'online'): int
{
    if ($source !== 'online') {
        $gate = trim(setting('gate_price_rupees'));
        if ($gate !== '' && is_numeric($gate)) {
            return rupees_to_paise($gate);
        }
    }
    return rupees_to_paise(setting('price_rupees', '0'));
}

/**
 * Serialise capacity check + insert so two buyers can't take the last seat.
 * MySQL: named lock. SQLite: the write lock of an immediate transaction.
 */
function with_capacity_lock(callable $fn): mixed
{
    $pdo = db();
    if (db_driver() === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
    $got = (int) q_val("SELECT GET_LOCK('tk_capacity', 10)");
    if ($got !== 1) {
        throw new RuntimeException('Busy, please try again.');
    }
    try {
        return $fn();
    } finally {
        q_val("SELECT RELEASE_LOCK('tk_capacity')");
    }
}

/** Accent colour, sanitised for safe use in CSS. */
function accent_color(): string
{
    $c = setting('accent_color', '#ff522b');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#ff522b';
}

/** Extract the src of a pasted Google Maps <iframe>, or accept a bare URL. */
function extract_map_src(string $input): string
{
    $input = trim($input);
    if (preg_match('/src=["\']([^"\']+)["\']/i', $input, $m)) {
        $input = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return preg_match('~^https://(www\.)?google\.[a-z.]+/maps/embed~i', $input) ? $input : '';
}

/** Safe external link (http/https only). */
function safe_url(string $u): string
{
    $u = trim($u);
    return preg_match('~^https?://~i', $u) ? $u : '';
}

// ---- CSV ----------------------------------------------------------------------

/** Prevent spreadsheet formula injection. */
function csv_cell(mixed $v): string
{
    $s = (string) $v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $s = "'" . $s;
    }
    return $s;
}

/**
 * Escape text for the wide display font. Spaces are rendered in the body
 * font so word gaps look right whether or not the display font has loaded.
 */
function display_text(string $s): string
{
    return implode('<span class="sp"> </span>', array_map('e', preg_split('/\s+/u', trim($s))));
}
