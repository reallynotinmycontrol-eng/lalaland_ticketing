<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/*
 * POST {token} or {booking_id}  →  {result, name, quantity, ref, checked_in_at, checked_in_by, counts}
 * result: allowed | already_used | invalid | not_paid | cancelled
 * The decision is one atomic UPDATE (see check_in()).
 */

require_method('POST');
$user = require_role('staff', true);
require_csrf();

$in = json_input();
if (isset($in['booking_id'])) {
    $key = ['booking_id' => (int) $in['booking_id']];
} else {
    $raw = trim((string) ($in['token'] ?? ''));
    $key = ['token' => extract_token($raw) ?? mb_substr($raw, 0, 255)];
}

$out = check_in($key, (int) $user['id']);
$out['counts'] = gate_counts();
json_out($out);

/** Accept a full ticket URL (…/ticket.php?t=TOKEN) or a bare 32-hex token. */
function extract_token(string $raw): ?string
{
    if (is_token(strtolower($raw))) {
        return strtolower($raw);
    }
    $query = parse_url($raw, PHP_URL_QUERY);
    if (is_string($query)) {
        parse_str($query, $params);
        $t = strtolower((string) ($params['t'] ?? ''));
        if (is_token($t)) {
            return $t;
        }
    }
    return null;
}
