# Event Ticketing

A standalone ticketing site for one event at a time: Razorpay payments, QR tickets by email and WhatsApp, a phone-browser gate scanner with one-time entry, gate and box office sales, and an admin panel.

Plain PHP 8.1+ and MySQL. No framework, no Composer, no build step. Runs on Hostinger Business shared hosting.

---

## Deploy to Hostinger

1. **Subdomain.** hPanel → Domains → Subdomains → create `tickets.yourwebsite.com`. Note its folder (e.g. `public_html/tickets`).
2. **SSL.** hPanel → Security → SSL → install the free certificate for the subdomain. Check that `https://tickets.yourwebsite.com` loads. The scanner camera and Razorpay both need HTTPS.
3. **Database.** hPanel → Databases → MySQL → create a database and user. Note the name, user and password.
4. **Upload.** Upload the *contents* of this folder into the subdomain's folder (File Manager → upload the zip → Extract). `index.php` must sit directly inside the subdomain folder.
5. **Config.** In `app/`, copy `config.sample.php` to `config.php` and fill in:
   - `base_url`: `https://tickets.yourwebsite.com` (no trailing slash)
   - `db`: name, user, password from step 3
   - `razorpay`: your **test** keys for now (Razorpay Dashboard → Account & Settings → API Keys)
   - leave `mail.enabled` false and `whatsapp.provider` as `none` until those accounts exist
6. **Install.** Open `https://tickets.yourwebsite.com/install.php`. It checks the server, creates the tables, and asks for your admin username and password. It locks itself afterwards.
7. **Event.** You land on Admin → Event. Fill in name, date, venue, price and capacity, then switch **Online sales open** on.
8. **Razorpay webhook (test mode).** Razorpay Dashboard → Webhooks → Add: URL `https://tickets.yourwebsite.com/webhook.php`, events `order.paid` and `payment.failed`, and a secret you invent. Put the same secret in `razorpay.webhook_secret`.
9. **Test.** Buy a ticket with UPI ID `success@razorpay`. Scan it twice from `/scan.php` on your phone: green, then red.
10. **Check privacy.** `https://tickets.yourwebsite.com/app/config.php` must show **403 Forbidden**.
11. **WordPress.** Add a "Buy Tickets" button linking to `https://tickets.yourwebsite.com`.

### Going live
After Razorpay KYC approval: generate **live** keys, add the webhook again in **live mode** (it has its own secret), put the live `key_id`, `key_secret` and `webhook_secret` in `config.php`. No code changes. Then buy one real ₹1 ticket (set the price to 1 temporarily), scan it twice, refund it in Razorpay and cancel it in admin.

### Event day
- Admin → Staff: create a login per gate phone. Staff sign in at `/login.php` and stay signed in for 16 hours.
- Test the scanner on every staff phone before gates open (Chrome on Android, Safari on iPhone; allow camera access).
- Admin → Gate poster: print it, and switch **Gate sales open** on.
- Export the bookings CSV before gates open as a fallback list.
- Scanning needs internet. Arrange Wi-Fi or a hotspot for the scanning phones.

---

## Branding
Styled to match lalalandflea.com: cream `#FDFDF3`/`#FFFACF`, orange-red `#FF522B` (the admin "Accent colour"), sunshine `#FFD600`, periwinkle `#8585FF` headings with a black outline, pink `#FF007A`, Poppins for text. The main site's display face (Woodburger) is a custom font, so the app uses the free Google font **Rubik Mono One** as a close stand-in. In Admin → Event, set **Logo image URL** (e.g. the logo from your WordPress media library) and **Banner image URL** for the hero photo.

## Email setup (SMTP)
Use Brevo, Zoho Mail or Amazon SES, not the Hostinger mailbox. Verify your sending domain (SPF and DKIM DNS records, which the provider shows you) so tickets don't land in spam. Then fill in `mail.*` in `config.php` and set `mail.enabled` to `true`.

## WhatsApp setup
You need the WhatsApp Business API through Meta directly, Interakt or AiSensy, with a dedicated number not used in the normal WhatsApp app.

Create one **Utility** template named as in `whatsapp.template_name`:
- **Header:** Image
- **Body** (4 variables, in this order):
  `Hi {{1}}, your ticket for {{2}} on {{3}} is confirmed. Show the QR code above at the entry gate. View your ticket: {{4}}`

After Meta approves it, set `whatsapp.provider` and that provider's key in `config.php`. For AiSensy, `template_name` is the **API campaign** name linked to the template.

The Meta adapter follows the documented Cloud API format. The Interakt and AiSensy adapters follow their public APIs as known at build time: send one test ticket and check its WhatsApp status in Admin → Bookings before relying on them. If a send fails, the exact provider error is shown there.

---

## For developers

### Layout
```
index.php            event page + booking form (?from=gate → short gate form)
order.php            POST JSON: validate, hold seats, pending booking, Razorpay order
verify.php           POST JSON: checkout signature → issue ticket
webhook.php          Razorpay webhook (order.paid, payment.failed), raw-body HMAC
ticket.php qr.php ics.php   ticket page, QR PNG, calendar file (by secret token)
login.php logout.php scan.php boxoffice.php install.php
api/checkin.php      atomic one-time check-in
api/search.php       manual lookup;  api/stats.php  scanner counter
admin/               dashboard, bookings, settings, staff, export, gate-poster
assets/              style.css, checkout.js, scan.js
app/                 private (blocked by .htaccess): config, logic, logs, bundled libs
```

### The rules that matter
- **Ticket issued exactly once:** `UPDATE … SET status='paid' WHERE rzp_order_id=? AND status IN ('pending','failed')`. Only the request that changes the row sends email and WhatsApp (`issue_ticket_for_order()` in `app/tickets.php`).
- **Entry exactly once:** `UPDATE … SET checked_in_at=? WHERE token=? AND status='paid' AND checked_in_at IS NULL`. One affected row means allowed; anything else is explained by re-reading the booking (`check_in()`).
- **Capacity:** paid + pending (last 15 min) + failed-with-order (last 15 min, Razorpay allows retries). The check and insert run under a MySQL named lock (`GET_LOCK`), so two buyers can't take the last seat.
- **Money:** always computed server-side, in paise. The webhook also checks `amount_paid` equals the booking amount.
- Email and WhatsApp are sent after the response is flushed (`litespeed_finish_request` / `fastcgi_finish_request`), so the buyer isn't kept waiting.

### Local development
```bash
cp app/config.sample.php app/config.php
# in config.php: 'base_url' => 'http://localhost:8080', 'db' => ['driver' => 'sqlite', ...]
PHP_CLI_SERVER_WORKERS=8 php -S localhost:8080
# then open http://localhost:8080/install.php
```
SQLite is for development only. All SQL is portable, and every timestamp comes from PHP in `Asia/Kolkata`. Note that `php -S` ignores `.htaccess`, so `/app` is only blocked on Apache/LiteSpeed. Optional `razorpay.api_base` points the Razorpay client at a mock server for tests.

### Bundled libraries (`app/lib/`)
PHPMailer 6.10.0 (LGPL-2.1), chillerlan/php-qrcode 5.0.5 (MIT), chillerlan/php-settings-container 3.3.0 (MIT). They're loaded by a small PSR-4 autoloader in `app/bootstrap.php`. The scanner uses html5-qrcode 2.3.8 from unpkg.

### Beyond the spec
- `tk_attempts` table, a 5th table for login lockout and the order rate limit (shared hosting has no guaranteed APCu/Redis).
- `cancelled_at` column on bookings, and an `undo` result in the scan log for admin undos.
- Box office price is the gate price when set, otherwise the normal price.
- `.ics` calendar download alongside the Google Calendar link; optional event end time setting.
- Admin can "Mark as entered" from a booking (same atomic rule).
- A payment that arrives after an admin cancelled the pending booking is not re-issued; it's logged for manual refund.
- A webhook arriving after the 15-minute hold expired still issues the ticket, because the money is already taken, so capacity can be exceeded by that booking in rare cases.
