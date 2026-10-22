<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$token = (string) ($_GET['t'] ?? '');
$b = booking_by_token($token);
$s = settings();

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (!$b) {
    http_response_code(404);
    render_header('Ticket not found', ['head' => '<meta name="robots" content="noindex">']);
    echo '<main class="wrap narrow"><div class="card center"><h1>Ticket not found</h1><p class="muted">Check the link in your WhatsApp or email message.</p><p><a class="btn" href="/">Event page</a></p></div></main>';
    render_footer();
    exit;
}

$status = $b['status'];
$mapLink = safe_url($s['event_map_link']);
$start = new DateTimeImmutable($s['event_datetime'] ?: 'now');
$end = $s['event_end_datetime'] ? new DateTimeImmutable($s['event_end_datetime']) : $start->modify('+3 hours');
$utc = new DateTimeZone('UTC');
$gcal = 'https://calendar.google.com/calendar/render?' . http_build_query([
    'action'   => 'TEMPLATE',
    'text'     => $s['event_name'],
    'dates'    => $start->setTimezone($utc)->format('Ymd\THis\Z') . '/' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
    'location' => $s['event_venue'],
    'details'  => 'Your ticket: ' . ticket_url($b),
]);

$head = '<meta name="robots" content="noindex">';
if ($status === 'pending') {
    $head .= '<meta http-equiv="refresh" content="4">'; // wait for the webhook
}

render_header('Your ticket', ['head' => $head, 'body_class' => 'page-ticket']);
?>
<main class="wrap narrow ticket-wrap">
  <?php if (!empty($_GET['new']) && $status === 'paid'): ?>
  <div class="notice ok">Payment successful. Your ticket has also been sent to WhatsApp<?= $b['email'] ? ' and email' : '' ?>.</div>
  <?php endif; ?>

  <article class="ticket<?= $b['checked_in_at'] ? ' is-used' : '' ?><?= $status !== 'paid' ? ' is-inactive' : '' ?>">
    <header class="ticket-top">
      <p class="eyebrow">Admit <?= (int) $b['quantity'] ?></p>
      <h1><?= display_text($s['event_name']) ?></h1>
      <p class="ticket-when"><?= e(fmt_datetime($s['event_datetime'])) ?></p>
      <?php if ($s['event_venue']): ?><p class="ticket-where"><?= e($s['event_venue']) ?></p><?php endif; ?>
    </header>

    <div class="ticket-tear" aria-hidden="true"></div>

    <div class="ticket-qr">
      <?php if ($status === 'paid'): ?>
        <img src="/qr.php?t=<?= e($b['token']) ?>" alt="Ticket QR code" width="260" height="260">
        <?php if ($b['checked_in_at']): ?>
          <div class="stamp">Used at <?= e(fmt_time($b['checked_in_at'])) ?></div>
        <?php else: ?>
          <p class="muted small">Show this at the entry gate. One scan admits <?= (int) $b['quantity'] === 1 ? '1 person' : 'all ' . (int) $b['quantity'] . ' people' ?>.</p>
        <?php endif; ?>
      <?php elseif ($status === 'pending'): ?>
        <div class="spinner" aria-hidden="true"></div>
        <h2>Confirming your payment…</h2>
        <p class="muted small">This page refreshes by itself. If you paid, your ticket will appear here in a moment.</p>
      <?php elseif ($status === 'failed'): ?>
        <h2>Payment not completed</h2>
        <p class="muted small">No ticket was issued. If money was deducted, it is refunded automatically by your bank, or contact us.</p>
        <p><a class="btn" href="/">Try again</a></p>
      <?php else: ?>
        <h2>Ticket cancelled</h2>
        <p class="muted small">This ticket has been cancelled<?= $b['rzp_payment_id'] ? ' and refunded' : '' ?>. It will not be accepted at the gate.</p>
      <?php endif; ?>
    </div>

    <dl class="ticket-meta">
      <div><dt>Name</dt><dd><?= e($b['name']) ?></dd></div>
      <div><dt>Tickets</dt><dd><?= (int) $b['quantity'] ?></dd></div>
      <div><dt>Booking</dt><dd class="mono"><?= e($b['ref']) ?></dd></div>
    </dl>
  </article>

  <?php if ($status === 'paid'): ?>
  <div class="ticket-actions">
    <a class="btn" href="<?= e($gcal) ?>" target="_blank" rel="noopener">Add to Google Calendar</a>
    <a class="btn" href="/ics.php?t=<?= e($b['token']) ?>">Add to calendar (.ics)</a>
    <?php if ($mapLink): ?><a class="btn" href="<?= e($mapLink) ?>" target="_blank" rel="noopener">Directions</a><?php endif; ?>
  </div>
  <p class="center muted small">Tip: take a screenshot in case the signal is weak at the venue.</p>
  <?php endif; ?>
</main>
<?php render_footer();
