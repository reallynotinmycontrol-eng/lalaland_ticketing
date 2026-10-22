<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$admin = require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf(false);
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'add') {
        $username = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
        if (!valid_username($username)) {
            flash('Username: 3–40 characters, letters, numbers, dot, dash or underscore.', 'error');
        } elseif ($name === '') {
            flash('Enter the staff member’s name.', 'error');
        } elseif (strlen($password) < 8) {
            flash('Password must be at least 8 characters.', 'error');
        } elseif (q_val('SELECT COUNT(*) FROM tk_users WHERE username = ?', [$username]) > 0) {
            flash('That username is taken.', 'error');
        } else {
            create_user($username, $name, $password, $role);
            flash("Added $name ($username).");
        }
    } elseif ($action === 'toggle' && $id !== (int) $admin['id']) {
        q('UPDATE tk_users SET active = 1 - active WHERE id = ?', [$id]);
        flash('Updated.');
    } elseif ($action === 'reset') {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            flash('Password must be at least 8 characters.', 'error');
        } else {
            q('UPDATE tk_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('Password changed.');
        }
    }
    redirect('/admin/staff.php');
}

$users = q('SELECT id, username, name, role, active, created_at FROM tk_users ORDER BY role, name')->fetchAll();
$scans = [];
foreach (q("SELECT user_id, COUNT(*) AS n FROM tk_scan_log WHERE result = 'allowed' GROUP BY user_id")->fetchAll() as $r) {
    $scans[(int) $r['user_id']] = (int) $r['n'];
}

render_header('Staff', ['nav' => 'admin', 'body_class' => 'page-admin']);
?>
<main class="wrap admin">
  <h1>Staff</h1>
  <section class="grid-2">
    <div class="card">
      <h2>Accounts</h2>
      <ul class="staff-list">
      <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $admin['id']; ?>
        <li class="<?= $u['active'] ? '' : 'inactive' ?>">
          <div>
            <strong><?= e($u['name']) ?></strong>
            <span class="pill"><?= e($u['role']) ?></span>
            <?php if (!$u['active']): ?><span class="pill off">disabled</span><?php endif; ?>
            <div class="muted small"><?= e($u['username']) ?> · <?= $scans[(int) $u['id']] ?? 0 ?> check-ins</div>
          </div>
          <div class="row-actions">
            <details>
              <summary class="btn">Reset password</summary>
              <form method="post" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="text" name="password" minlength="8" placeholder="New password" required autocomplete="off">
                <button class="btn btn-primary">Save</button>
              </form>
            </details>
            <?php if (!$self): ?>
            <form method="post">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn"><?= $u['active'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
      </ul>
    </div>

    <form method="post" class="card" autocomplete="off">
      <h2>Add staff</h2>
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <label class="field"><span>Name</span><input name="name" required maxlength="120"></label>
      <label class="field"><span>Username</span><input name="username" required autocapitalize="none" pattern="[A-Za-z0-9._\-]{3,40}"></label>
      <label class="field"><span>Password <em>(min 8 characters)</em></span><input name="password" type="text" minlength="8" required></label>
      <label class="field"><span>Role</span>
        <select name="role"><option value="staff">Staff — scanner, search, box office</option><option value="admin">Admin — everything</option></select>
      </label>
      <button class="btn btn-primary btn-block">Add</button>
      <p class="muted small">Staff sign in at <span class="mono"><?= e(url('/login.php')) ?></span> and land on the scanner.</p>
    </form>
  </section>
</main>
<?php render_footer(['nav' => 'admin']);
