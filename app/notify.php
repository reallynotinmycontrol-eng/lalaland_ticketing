<?php
declare(strict_types=1);

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPMailer\PHPMailer\PHPMailer;

/*
 * QR rendering and ticket delivery (email + WhatsApp).
 * A delivery failure never affects the ticket itself: we only record the
 * outcome in email_status / wa_status so the admin can see it and resend.
 */

function qr_png(string $data, int $scale = 10): string
{
    $options = new QROptions([
        'outputType'    => QROutputInterface::GDIMAGE_PNG,
        'outputBase64'  => false,
        'eccLevel'      => EccLevel::M,
        'scale'         => $scale,
        'quietzoneSize' => 4,
    ]);
    return (new QRCode($options))->render($data);
}

/** Send by every enabled channel. $channels: subset of ['email','whatsapp']. */
function deliver_ticket(array $booking, array $channels = ['email', 'whatsapp']): array
{
    $result = [];
    if (in_array('email', $channels, true)) {
        $result['email'] = send_ticket_email($booking);
        q('UPDATE tk_bookings SET email_status = ? WHERE id = ?', [mb_substr($result['email'], 0, 255), $booking['id']]);
    }
    if (in_array('whatsapp', $channels, true)) {
        $result['whatsapp'] = send_ticket_whatsapp($booking);
        q('UPDATE tk_bookings SET wa_status = ? WHERE id = ?', [mb_substr($result['whatsapp'], 0, 255), $booking['id']]);
    }
    return $result;
}

// ---- Email --------------------------------------------------------------------

/** @return string 'sent', 'skipped' or an error message */
function send_ticket_email(array $b): string
{
    if (trim((string) $b['email']) === '') {
        return 'skipped';
    }
    if (!config('mail.enabled', false)) {
        return 'skipped';
    }
    $s = settings();
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) config('mail.host');
        $mail->Port = (int) config('mail.port', 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) config('mail.username');
        $mail->Password = (string) config('mail.password');
        $mail->SMTPSecure = config('mail.encryption') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom((string) config('mail.from_email'), (string) config('mail.from_name'));
        if (config('mail.reply_to')) {
            $mail->addReplyTo((string) config('mail.reply_to'));
        }
        $mail->addAddress((string) $b['email'], (string) $b['name']);

        $mail->Subject = 'Your ticket for ' . $s['event_name'] . ' — ' . $b['ref'];
        $mail->addStringEmbeddedImage(qr_png(ticket_url($b), 8), 'ticketqr', 'ticket-qr.png', PHPMailer::ENCODING_BASE64, 'image/png');
        $mail->isHTML(true);
        $mail->Body = ticket_email_html($b, $s);
        $mail->AltBody = ticket_email_text($b, $s);
        $mail->send();
        return 'sent';
    } catch (Throwable $e) {
        $msg = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
        app_log('error', 'Email failed', ['ref' => $b['ref'], 'error' => $msg]);
        return 'Error: ' . $msg;
    }
}

function ticket_email_html(array $b, array $s): string
{
    $accent = accent_color();
    $map = safe_url($s['event_map_link']);
    $rows = [
        'Date'     => fmt_datetime($s['event_datetime']),
        'Venue'    => $s['event_venue'],
        'Name'     => $b['name'],
        'Admits'   => $b['quantity'] . ' ' . ((int) $b['quantity'] === 1 ? 'person' : 'people'),
        'Booking'  => $b['ref'],
    ];
    $rowsHtml = '';
    foreach ($rows as $k => $v) {
        if ($v === '') {
            continue;
        }
        $rowsHtml .= '<tr><td style="padding:6px 0;color:#6b6b6b;width:90px;vertical-align:top">' . e($k)
            . '</td><td style="padding:6px 0;color:#141414;font-weight:600">' . e((string) $v) . '</td></tr>';
    }
    $ticketUrl = e(ticket_url($b));
    $qrFallback = e(qr_url($b));
    $mapHtml = $map ? '<p style="margin:8px 0 0"><a href="' . e($map) . '" style="color:' . $accent . '">Get directions</a></p>' : '';
    $terms = $s['terms'] ? '<p style="font-size:12px;color:#8a8a8a;line-height:1.5;margin:24px 0 0">' . nl2br(e($s['terms'])) . '</p>' : '';

    return '<!doctype html><html><body style="margin:0;background:#f4f2ee;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2ee;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;overflow:hidden">'
        . '<tr><td style="background:' . $accent . ';padding:20px 24px;color:#ffffff">'
        . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85">Your ticket</div>'
        . '<div style="font-size:22px;font-weight:700;margin-top:4px">' . e($s['event_name']) . '</div></td></tr>'
        . '<tr><td style="padding:24px" align="center">'
        . '<img src="cid:ticketqr" alt="Ticket QR code" width="240" height="240" style="display:block;width:240px;height:240px;border:0">'
        . '<div style="font-size:12px;color:#8a8a8a;margin-top:6px">Show this QR at the entry gate. It admits entry once.</div>'
        . '<div style="font-size:11px;color:#aaaaaa;margin-top:4px">QR not showing? <a href="' . $qrFallback . '" style="color:#8a8a8a">Open it here</a></div>'
        . '</td></tr>'
        . '<tr><td style="padding:0 24px"><table role="presentation" width="100%" style="font-size:15px;border-top:1px solid #eeeeee;padding-top:8px">' . $rowsHtml . '</table>' . $mapHtml . '</td></tr>'
        . '<tr><td style="padding:24px" align="center"><a href="' . $ticketUrl . '" style="display:inline-block;background:#141414;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:999px;font-weight:600">View ticket</a>'
        . $terms . '</td></tr>'
        . '</table><div style="font-size:12px;color:#9a9a9a;margin-top:12px">' . e((string) config('organiser_name')) . '</div>'
        . '</td></tr></table></body></html>';
}

function ticket_email_text(array $b, array $s): string
{
    return "Your ticket for {$s['event_name']}\n\n"
        . 'Date: ' . fmt_datetime($s['event_datetime']) . "\n"
        . ($s['event_venue'] ? "Venue: {$s['event_venue']}\n" : '')
        . "Name: {$b['name']}\n"
        . "Admits: {$b['quantity']}\n"
        . "Booking: {$b['ref']}\n\n"
        . 'View your ticket (QR code): ' . ticket_url($b) . "\n"
        . ($s['event_map_link'] ? 'Directions: ' . $s['event_map_link'] . "\n" : '')
        . ($s['terms'] ? "\n" . $s['terms'] . "\n" : '');
}

// ---- WhatsApp -----------------------------------------------------------------

/** @return string 'sent', 'skipped' or an error message */
function send_ticket_whatsapp(array $b): string
{
    $provider = (string) config('whatsapp.provider', 'none');
    if ($provider === 'none' || $provider === '') {
        return 'skipped';
    }
    $s = settings();
    // Template body variables, in this order: {{1}} name, {{2}} event, {{3}} date/time, {{4}} ticket link
    $vars = [
        (string) $b['name'],
        (string) $s['event_name'],
        fmt_datetime($s['event_datetime']),
        ticket_url($b),
    ];
    $image = qr_url($b);

    try {
        $resp = match ($provider) {
            'meta'     => wa_send_meta($b['phone'], $vars, $image),
            'interakt' => wa_send_interakt($b['phone'], $vars, $image),
            'aisensy'  => wa_send_aisensy($b['phone'], $b['name'], $vars, $image),
            default    => throw new RuntimeException('Unknown WhatsApp provider: ' . $provider),
        };
        return $resp;
    } catch (Throwable $e) {
        app_log('error', 'WhatsApp failed', ['ref' => $b['ref'], 'provider' => $provider, 'error' => $e->getMessage()]);
        return 'Error: ' . $e->getMessage();
    }
}

function wa_send_meta(string $phone, array $vars, string $imageUrl): string
{
    $url = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        config('whatsapp.meta_api_version', 'v21.0'),
        rawurlencode((string) config('whatsapp.meta_phone_number_id'))
    );
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'template',
        'template'          => [
            'name'       => (string) config('whatsapp.template_name'),
            'language'   => ['code' => (string) config('whatsapp.language', 'en')],
            'components' => [
                ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $imageUrl]]]],
                ['type' => 'body', 'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => $v], $vars)],
            ],
        ],
    ];
    [$status, $data, $raw] = http_json('POST', $url, $payload, [
        'Authorization: Bearer ' . config('whatsapp.meta_access_token'),
    ]);
    if ($status >= 200 && $status < 300 && !empty($data['messages'][0]['id'])) {
        return 'sent';
    }
    throw new RuntimeException($data['error']['message'] ?? ('HTTP ' . $status . ' ' . mb_substr($raw, 0, 150)));
}

/** Interakt public API. Verify field names against Interakt's current docs. */
function wa_send_interakt(string $phone, array $vars, string $imageUrl): string
{
    [$cc, $local] = split_country_code($phone);
    $payload = [
        'countryCode' => '+' . $cc,
        'phoneNumber' => $local,
        'type'        => 'Template',
        'template'    => [
            'name'         => (string) config('whatsapp.template_name'),
            'languageCode' => (string) config('whatsapp.language', 'en'),
            'headerValues' => [$imageUrl],
            'bodyValues'   => $vars,
        ],
    ];
    [$status, $data, $raw] = http_json('POST', 'https://api.interakt.ai/v1/public/message/', $payload, [
        'Authorization: Basic ' . config('whatsapp.interakt_api_key'),
    ]);
    if ($status >= 200 && $status < 300 && ($data['result'] ?? false)) {
        return 'sent';
    }
    throw new RuntimeException($data['message'] ?? ('HTTP ' . $status . ' ' . mb_substr($raw, 0, 150)));
}

/** AiSensy campaign API. Verify field names against AiSensy's current docs. */
function wa_send_aisensy(string $phone, string $name, array $vars, string $imageUrl): string
{
    $payload = [
        'apiKey'         => (string) config('whatsapp.aisensy_api_key'),
        'campaignName'   => (string) config('whatsapp.template_name'),
        'destination'    => $phone,
        'userName'       => $name,
        'templateParams' => $vars,
        'media'          => ['url' => $imageUrl, 'filename' => 'ticket-qr.png'],
    ];
    [$status, $data, $raw] = http_json('POST', 'https://backend.aisensy.com/campaign/t1/api/v2', $payload);
    if ($status >= 200 && $status < 300) {
        return 'sent';
    }
    throw new RuntimeException($data['message'] ?? $data['errorMessage'] ?? ('HTTP ' . $status . ' ' . mb_substr($raw, 0, 150)));
}

/** @return array{0:string,1:string} [country code, local number] */
function split_country_code(string $phone): array
{
    $cc = (string) config('default_country_code', '91');
    if (str_starts_with($phone, $cc) && strlen($phone) > strlen($cc) + 6) {
        return [$cc, substr($phone, strlen($cc))];
    }
    return [substr($phone, 0, 2), substr($phone, 2)];
}

/** @return array{0:int,1:array,2:string} */
function http_json(string $method, string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Network error: ' . $err);
    }
    $data = json_decode((string) $raw, true);
    return [$status, is_array($data) ? $data : [], (string) $raw];
}
