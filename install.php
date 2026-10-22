<?php
declare(strict_types=1);

/*
 * One-time setup: checks the server, creates the tables and the first admin.
 * Refuses to run once an admin user exists.
 */

require __DIR__ . '/app/bootstrap.php';

$checks = [
    'PHP 8.1 or newer'          => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO ' . db_driver()        => extension_loaded('pdo_' . db_driver()),
    'GD (QR images)'            => extension_loaded('gd'),
    'cURL (Razorpay, WhatsApp)' => extension_loaded('curl'),
    'mbstring'                  => extension_loaded('mbstring'),
    'OpenSSL'                   => extension_loaded('openssl'),
    'app/logs is writable'      => is_writable(APP_DIR . '/logs'),
];
$dbError = '';
try {
    db();
    $checks['Database connection'] = true;
} catch (Throwable $e) {
    $checks['Database connection'] = false;
    $dbError = $e->getMessage();
}
$ok = !in_array(false, $checks, true);

// Already installed?
if ($ok) {
    install_schema(); // idempotent: creates missing tables and default settings
    if ((int) q_val("SELECT COUNT(*) FROM tk_users WHERE role = 'admin'") > 0) {
        http_response_code(403);
        render_header('Setup', ['nav' => 'none']);
        echo '<main class="wrap narrow"><div class="card"><h1>Already installed</h1><p>An admin account exists, so setup is locked.</p><p><a class="btn btn-primary" href="/login.php">Sign in</a></p></div></main>';
        render_footer(['nav' => 'none']);
        exit;
    }
}

$error = '';
if ($ok && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $username = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!valid_username($username)) {
        $error = 'Username: 3–40 characters, letters, numbers, dot, dash or underscore.';
    } elseif ($name === '') {
        $error = 'Enter your name.';
    } elseif (strlen($password) < 10) {
        $error = 'Admin password must be at least 10 characters.';
    } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
        $error = 'Passwords do not match.';
    } else {
        // Guard against a double submit racing past the check above.
        if ((int) q_val("SELECT COUNT(*) FROM tk_users WHERE role = 'admin'") === 0) {
            create_user($username, $name, $password, 'admin');
            app_log('info', 'Installed; admin created', ['username' => $username]);
        }
        attempt_login($username, $password);
        flash('Setup complete. Next: fill in your event details and turn sales on.');
        redirect('/admin/settings.php');
    }
}

$https = str_starts_with((string) config('base_url'), 'https://');
$keysSet = !str_contains((string) config('razorpay.key_id'), 'XXXX') && config('razorpay.key_secret') !== '';

render_header('Setup', ['nav' => 'none', 'body_class' => 'page-install']);
?>
<main class="wrap narrow">
  <div class="card">
    <p class="eyebrow">One-time setup</p>
    <h1>Install ticketing</h1>
    <ul class="checks">
      <?php foreach ($checks as $label => $pass): ?>
      <li class="<?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✓' : '✗' ?> <?= e($label) ?></li>
      <?php endforeach; ?>
      <li class="<?= $https ? 'pass' : 'warn' ?>"><?= $https ? '✓' : '!' ?> base_url uses https <?= $https ? '' : '(needed for phone cameras and payments)' ?></li>
      <li class="<?= $keysSet ? 'pass' : 'warn' ?>"><?= $keysSet ? '✓' : '!' ?> Razorpay keys in config <?= $keysSet ? '' : '(add them before taking payments)' ?></li>
    </ul>
    <?php if ($dbError): ?>
      <p class="form-error">Database error: <?= e(config('debug') ? $dbError : 'check the db settings in app/config.php') ?></p>
    <?php endif; ?>

    <?php if ($ok): ?>
    <form method="post">
      <?= csrf_field() ?>
      <h2>Create the admin account</h2>
      <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
      <label class="field"><span>Your name</span><input name="name" required value="<?= e((string) ($_POST['name'] ?? '')) ?>"></label>
      <label class="field"><span>Username</span><input name="username" required autocapitalize="none" value="<?= e((string) ($_POST['username'] ?? '')) ?>"></label>
      <label class="field"><span>Password <em>(min 10 characters)</em></span><input name="password" type="password" minlength="10" required autocomplete="new-password"></label>
      <label class="field"><span>Repeat password</span><input name="password2" type="password" minlength="10" required autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block">Create admin &amp; finish</button>
    </form>
    <?php else: ?>
      <p>Fix the items marked ✗ above, then reload this page.</p>
    <?php endif; ?>
  </div>
</main>
<?php render_footer(['nav' => 'none']);
