<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

/* CSV of all bookings. UTF-8 BOM so Excel shows ₹ and Indian names correctly. */

require_role('admin');

$label = setting('extra_field_label') ?: 'Extra';
$users = [];
foreach (q('SELECT id, name FROM tk_users')->fetchAll() as $u) {
    $users[(int) $u['id']] = $u['name'];
}

$filename = preg_replace('/[^A-Za-z0-9]+/', '-', setting('event_name')) . '-bookings-' . date('Y-m-d-Hi') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Booking ID', 'Name', 'Phone', 'Email', $label, 'Tickets', 'Amount (₹)', 'Channel', 'Payment method', 'Status',
    'Created', 'Paid', 'Entered at', 'Entered by', 'Sold by', 'Razorpay order', 'Razorpay payment', 'Email status', 'WhatsApp status'], ',', '"', '');

$stmt = q('SELECT * FROM tk_bookings ORDER BY id');
while ($b = $stmt->fetch()) {
    fputcsv($out, array_map('csv_cell', [
        // "91 98765 43210": the spaces keep Excel from turning it into 9.19877E+11
        $b['ref'], $b['name'], ltrim(pretty_phone($b['phone']), '+'), $b['email'], $b['extra_value'] ?? '', $b['quantity'],
        number_format($b['amount_paise'] / 100, 2, '.', ''), $b['source'], $b['payment_method'], $b['status'],
        $b['created_at'], $b['paid_at'] ?? '', $b['checked_in_at'] ?? '',
        $users[(int) $b['checked_in_by']] ?? '', $users[(int) $b['created_by']] ?? '',
        $b['rzp_order_id'] ?? '', $b['rzp_payment_id'] ?? '', $b['email_status'] ?? '', $b['wa_status'] ?? '',
    ]), ',', '"', '');
}
fclose($out);
