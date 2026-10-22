<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

start_session();
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
// Only allow local paths as redirect targets.
if (!preg_match('~^/(?!/)[A-Za-z0-9/_\-.?=&%]*$~', $next)) {
    $next = '';
}

if (current_user()) {
    redirect($next ?: (is_admin() ? '/admin/' : '/scan.php'));
}

$error = '';
$username = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $username = (string) ($_POST['username'] ?? '');
    $res = attempt_login($username, (string) ($_POST['password'] ?? ''));
    if ($res['ok']) {
        redirect($next ?: (is_admin() ? '/admin/' : '/scan.php'));
    }
    $error = $res['error'];
}

render_header('Sign in', ['nav' => 'none', 'body_class' => 'page-login']);
?>
<main class="wrap narrow">
  <form method="post" class="card login-card" autocomplete="on">
    <p class="eyebrow">Staff &amp; admin</p>
    <h1>Sign in</h1>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    <label class="field"><span>Username</span>
      <input name="username" value="<?= e($username) ?>" required autocapitalize="none" autocorrect="off" autocomplete="username" autofocus>
    </label>
    <label class="field"><span>Password</span>
      <input name="password" type="password" required autocomplete="current-password">
    </label>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
</main>
<?php render_footer(['nav' => 'none']);
