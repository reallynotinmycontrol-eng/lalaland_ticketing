<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/* GET ?q= phone, ref or name → up to 20 paid bookings (for manual check-in). */

require_role('staff', true);

$rows = search_bookings((string) ($_GET['q'] ?? ''), 20, true);
$staffNames = [];
$out = array_map(static function (array $b) use (&$staffNames): array {
    $by = null;
    if ($b['checked_in_by']) {
        $id = (int) $b['checked_in_by'];
        $staffNames[$id] ??= (string) q_val('SELECT name FROM tk_users WHERE id = ?', [$id]);
        $by = $staffNames[$id];
    }
    return [
        'id'              => (int) $b['id'],
        'ref'             => $b['ref'],
        'name'            => $b['name'],
        'phone'           => mask_phone($b['phone']),
        'quantity'        => (int) $b['quantity'],
        'source'          => $b['source'],
        'checked_in_time' => fmt_time($b['checked_in_at']),
        'checked_in_by'   => $by,
    ];
}, $rows);

json_out(['results' => $out]);
