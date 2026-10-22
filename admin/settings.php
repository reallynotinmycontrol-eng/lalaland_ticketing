<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

require_role('admin');
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $p = static fn(string $k): string => trim((string) ($_POST[$k] ?? ''));
    $toDb = static function (string $local): string {
        if ($local === '') {
            return '';
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $local);
        return $d ? $d->format('Y-m-d H:i:00') : '';
    };

    $new = [
        'event_name'           => mb_substr($p('event_name'), 0, 120),
        'event_tagline'        => mb_substr($p('event_tagline'), 0, 200),
        'event_description'    => mb_substr($p('event_description'), 0, 5000),
        'event_datetime'       => $toDb($p('event_datetime')),
        'event_end_datetime'   => $toDb($p('event_end_datetime')),
        'event_venue'          => mb_substr($p('event_venue'), 0, 200),
        'event_map_embed'      => extract_map_src($p('event_map_embed')),
        'event_map_link'       => safe_url($p('event_map_link')),
        'banner_url'           => safe_url($p('banner_url')),
        'price_rupees'         => $p('price_rupees'),
        'gate_price_rupees'    => $p('gate_price_rupees'),
        'capacity'             => $p('capacity'),
        'max_per_booking'      => $p('max_per_booking'),
        'sales_open'           => isset($_POST['sales_open']) ? '1' : '0',
        'gate_sales_open'      => isset($_POST['gate_sales_open']) ? '1' : '0',
        'extra_field_label'    => mb_substr($p('extra_field_label'), 0, 60),
        'extra_field_required' => isset($_POST['extra_field_required']) ? '1' : '0',
        'terms'                => mb_substr($p('terms'), 0, 5000),
        'accent_color'         => $p('accent_color'),
    ];

    if ($new['event_name'] === '') {
        $errors[] = 'Event name is required.';
    }
    if ($new['event_datetime'] === '') {
        $errors[] = 'Event date and time is required.';
    }
    if (!is_numeric($new['price_rupees']) || (float) $new['price_rupees'] < 1) {
        $errors[] = 'Price must be at least ₹1.';
    }
    if ($new['gate_price_rupees'] !== '' && (!is_numeric($new['gate_price_rupees']) || (float) $new['gate_price_rupees'] < 1)) {
        $errors[] = 'Gate price must be at least ₹1, or empty to use the normal price.';
    }
    if (!ctype_digit($new['capacity'])) {
        $errors[] = 'Capacity must be a whole number.';
    }
    if (!ctype_digit($new['max_per_booking']) || (int) $new['max_per_booking'] < 1 || (int) $new['max_per_booking'] > 50) {
        $errors[] = 'Max tickets per booking must be 1 to 50.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $new['accent_color'])) {
        $new['accent_color'] = '#e8452c';
    }
    if ($p('event_map_embed') !== '' && $new['event_map_embed'] === '') {
        $errors[] = 'Map embed must be the Google Maps "Embed a map" code (an iframe with google.com/maps/embed).';
    }

    if (!$errors) {
        foreach ($new as $k => $v) {
            set_setting($k, $v);
        }
        settings(true);
        flash('Event settings saved.');
        redirect('/admin/settings.php');
    }
    $s = array_merge(settings(), $new);
} else {
    $s = settings();
}

$toLocal = static fn(string $dt): string => $dt ? (new DateTimeImmutable($dt))->format('Y-m-d\TH:i') : '';

render_header('Event settings', ['nav' => 'admin', 'body_class' => 'page-admin']);
?>
<main class="wrap admin">
  <div class="admin-head">
    <h1>Event settings</h1>
    <a class="btn" href="/" target="_blank">Preview event page ↗</a>
  </div>
  <?php foreach ($errors as $err): ?><p class="form-error"><?= e($err) ?></p><?php endforeach; ?>

  <form method="post" class="settings">
    <?= csrf_field() ?>

    <section class="card">
      <h2>Sales</h2>
      <label class="toggle"><input type="checkbox" name="sales_open" <?= $s['sales_open'] === '1' ? 'checked' : '' ?>> <span>Online sales open</span></label>
      <label class="toggle"><input type="checkbox" name="gate_sales_open" <?= $s['gate_sales_open'] === '1' ? 'checked' : '' ?>> <span>Gate sales open (poster QR)</span></label>
      <div class="grid-3">
        <label class="field"><span>Price (₹)</span><input name="price_rupees" inputmode="decimal" value="<?= e($s['price_rupees']) ?>" required></label>
        <label class="field"><span>Gate / box office price (₹)</span><input name="gate_price_rupees" inputmode="decimal" value="<?= e($s['gate_price_rupees']) ?>" placeholder="Same as price"></label>
        <label class="field"><span>Capacity (people)</span><input name="capacity" inputmode="numeric" value="<?= e($s['capacity']) ?>" required></label>
        <label class="field"><span>Max tickets per booking</span><input name="max_per_booking" inputmode="numeric" value="<?= e($s['max_per_booking']) ?>" required></label>
      </div>
    </section>

    <section class="card">
      <h2>Event</h2>
      <label class="field"><span>Event name</span><input name="event_name" value="<?= e($s['event_name']) ?>" required maxlength="120"></label>
      <label class="field"><span>Tagline</span><input name="event_tagline" value="<?= e($s['event_tagline']) ?>" maxlength="200"></label>
      <div class="grid-2">
        <label class="field"><span>Starts</span><input type="datetime-local" name="event_datetime" value="<?= e($toLocal($s['event_datetime'])) ?>" required></label>
        <label class="field"><span>Ends <em>(optional, for calendars)</em></span><input type="datetime-local" name="event_end_datetime" value="<?= e($toLocal($s['event_end_datetime'])) ?>"></label>
      </div>
      <label class="field"><span>Description</span><textarea name="event_description" rows="6"><?= e($s['event_description']) ?></textarea></label>
      <label class="field"><span>Banner image URL <em>(https, wide image ~1600×900)</em></span><input name="banner_url" value="<?= e($s['banner_url']) ?>" placeholder="https://yourwebsite.com/wp-content/uploads/banner.jpg"></label>
      <label class="field"><span>Accent colour</span><input type="color" name="accent_color" value="<?= e($s['accent_color']) ?>"></label>
    </section>

    <section class="card">
      <h2>Venue</h2>
      <label class="field"><span>Venue name and address</span><input name="event_venue" value="<?= e($s['event_venue']) ?>" maxlength="200"></label>
      <label class="field"><span>Google Maps embed code <em>(Maps → Share → Embed a map → Copy HTML)</em></span>
        <textarea name="event_map_embed" rows="3" placeholder='<iframe src="https://www.google.com/maps/embed?pb=..."></iframe>'><?= e($s['event_map_embed'] ? '<iframe src="' . $s['event_map_embed'] . '"></iframe>' : '') ?></textarea>
      </label>
      <label class="field"><span>Directions link <em>(Maps → Share → Copy link)</em></span><input name="event_map_link" value="<?= e($s['event_map_link']) ?>" placeholder="https://maps.app.goo.gl/..."></label>
    </section>

    <section class="card">
      <h2>Booking form</h2>
      <div class="grid-2">
        <label class="field"><span>Extra field label <em>(empty = no extra field)</em></span><input name="extra_field_label" value="<?= e($s['extra_field_label']) ?>" placeholder="e.g. College"></label>
        <label class="toggle"><input type="checkbox" name="extra_field_required" <?= $s['extra_field_required'] === '1' ? 'checked' : '' ?>> <span>Extra field is required</span></label>
      </div>
      <label class="field"><span>Terms (shown on the form, ticket email)</span><textarea name="terms" rows="5"><?= e($s['terms']) ?></textarea></label>
    </section>

    <div class="sticky-save"><button class="btn btn-primary">Save settings</button></div>
  </form>
</main>
<?php render_footer(['nav' => 'admin']);
