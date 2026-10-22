<?php
declare(strict_types=1);

const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 300;

/** Currently signed-in user (re-checked against the DB on every request). */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    start_session();
    $id = (int) ($_SESSION['uid'] ?? 0);
    $user = null;
    if ($id > 0) {
        $row = q_row('SELECT id, username, name, role, active FROM tk_users WHERE id = ?', [$id]);
        if ($row && (int) $row['active'] === 1) {
            $user = $row;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/**
 * Guard a page or API endpoint.
 * @param string $role 'staff' (staff or admin) or 'admin'
 */
function require_role(string $role = 'staff', bool $json = false): array
{
    $user = current_user();
    if (!$user) {
        if ($json) {
            json_error('Please sign in again.', 401);
        }
        redirect('/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/scan.php'));
    }
    if ($role === 'admin' && $user['role'] !== 'admin') {
        if ($json) {
            json_error('Not allowed.', 403);
        }
        http_response_code(403);
        render_header('Not allowed');
        echo '<main class="wrap narrow"><div class="card"><h1>Not allowed</h1><p>This page is for admins only.</p><p><a class="btn" href="/scan.php">Go to scanner</a></p></div></main>';
        render_footer();
        exit;
    }
    return $user;
}

/** @return array{ok:bool, error?:string} */
function attempt_login(string $username, string $password): array
{
    $username = mb_strtolower(trim($username));
    $bucket = 'login:' . $username . '|' . client_ip();

    if (rate_count($bucket, LOGIN_LOCK_SECONDS) >= LOGIN_MAX_FAILS) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please wait 5 minutes and try again.'];
    }

    $row = q_row('SELECT id, password_hash, active FROM tk_users WHERE username = ?', [$username]);
    // Always run password_verify so timing doesn't reveal which usernames exist.
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    $valid = password_verify($password, $hash);

    if (!$row || !$valid || (int) $row['active'] !== 1) {
        rate_hit($bucket);
        return ['ok' => false, 'error' => 'Wrong username or password.'];
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE tk_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }

    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    rate_clear($bucket);
    return ['ok' => true];
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

function create_user(string $username, string $name, string $password, string $role): int
{
    q(
        'INSERT INTO tk_users (username, name, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)',
        [mb_strtolower(trim($username)), trim($name), password_hash($password, PASSWORD_DEFAULT), $role, now()]
    );
    return (int) db()->lastInsertId();
}

function valid_username(string $u): bool
{
    return (bool) preg_match('/^[a-z0-9._-]{3,40}$/', mb_strtolower(trim($u)));
}
