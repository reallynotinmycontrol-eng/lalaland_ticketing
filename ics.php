<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

/* Calendar file (.ics) for a ticket. Works with Apple, Google and Outlook. */

$b = booking_by_token((string) ($_GET['t'] ?? ''));
if (!$b || $b['status'] !== 'paid') {
    http_response_code(404);
    exit;
}
$s = settings();
$utc = new DateTimeZone('UTC');
$start = new DateTimeImmutable($s['event_datetime'] ?: 'now');
$end = $s['event_end_datetime'] ? new DateTimeImmutable($s['event_end_datetime']) : $start->modify('+3 hours');

$esc = static fn(string $v): string => addcslashes(str_replace(["\r\n", "\n"], '\n', $v), ',;\\');

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//' . $esc((string) config('organiser_name')) . '//Tickets//EN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' . $b['ref'] . '@' . (parse_url((string) config('base_url'), PHP_URL_HOST) ?: 'tickets'),
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
    'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
    'SUMMARY:' . $esc($s['event_name']),
    'LOCATION:' . $esc($s['event_venue']),
    'DESCRIPTION:' . $esc('Booking ' . $b['ref'] . ' · Admit ' . $b['quantity'] . "\nTicket: " . ticket_url($b)),
    'URL:' . ticket_url($b),
    'END:VEVENT',
    'END:VCALENDAR',
];

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]+/', '-', $s['event_name']) . '.ics"');
header('Cache-Control: no-store');
echo implode("\r\n", $lines) . "\r\n";
