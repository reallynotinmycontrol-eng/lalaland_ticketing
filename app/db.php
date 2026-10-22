<?php
declare(strict_types=1);

/*
 * Database access. Production uses MySQL/MariaDB; SQLite is supported for
 * local development. All SQL in the app is written to run on both, and all
 * timestamps are produced by PHP (now()) in the configured time zone.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (db_driver() === 'sqlite') {
        $path = (string) config('db.sqlite_path');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host', 'localhost'),
            (int) config('db.port', 3306),
            config('db.name')
        );
        $pdo = new PDO($dsn, (string) config('db.user'), (string) config('db.password'), $options);
        $offset = (new DateTimeImmutable())->format('P');
        $pdo->exec("SET time_zone = '" . $offset . "'");
    }
    return $pdo;
}

function db_driver(): string
{
    return config('db.driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
}

/** Current time in the configured time zone, as a DATETIME string. */
function now(string $modify = ''): string
{
    $d = new DateTimeImmutable();
    if ($modify !== '') {
        $d = $d->modify($modify);
    }
    return $d->format('Y-m-d H:i:s');
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = []): mixed
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

// ---- Schema -----------------------------------------------------------------

function schema_sql(): array
{
    if (db_driver() === 'sqlite') {
        $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = '';
    } else {
        $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    $tables = [];

    $tables[] = "CREATE TABLE IF NOT EXISTS tk_bookings (
        id $pk,
        ref VARCHAR(16) NOT NULL,
        token CHAR(32) NOT NULL,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(20) NOT NULL,
        extra_value VARCHAR(255) NULL,
        quantity INT NOT NULL,
        amount_paise INT NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'online',
        payment_method VARCHAR(16) NOT NULL DEFAULT 'razorpay',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        rzp_order_id VARCHAR(64) NULL,
        rzp_payment_id VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        paid_at DATETIME NULL,
        checked_in_at DATETIME NULL,
        checked_in_by INT NULL,
        email_status VARCHAR(255) NULL,
        wa_status VARCHAR(255) NULL,
        created_by INT NULL,
        cancelled_at DATETIME NULL,
        ip VARCHAR(45) NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS tk_settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS tk_users (
        id $pk,
        username VARCHAR(60) NOT NULL,
        name VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL DEFAULT 'staff',
        active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS tk_scan_log (
        id $pk,
        booking_id INT NULL,
        scanned_value VARCHAR(255) NOT NULL DEFAULT '',
        result VARCHAR(20) NOT NULL,
        user_id INT NULL,
        created_at DATETIME NOT NULL
    )$tail";

    // Small helper table for login throttling and order rate limits
    // (shared hosting has no guaranteed APCu/Redis).
    $tables[] = "CREATE TABLE IF NOT EXISTS tk_attempts (
        id $pk,
        bucket VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL
    )$tail";

    $indexes = [
        'CREATE UNIQUE INDEX uq_bookings_ref ON tk_bookings (ref)',
        'CREATE UNIQUE INDEX uq_bookings_token ON tk_bookings (token)',
        'CREATE UNIQUE INDEX uq_bookings_order ON tk_bookings (rzp_order_id)',
        'CREATE INDEX ix_bookings_status ON tk_bookings (status, created_at)',
        'CREATE INDEX ix_bookings_phone ON tk_bookings (phone)',
        'CREATE INDEX ix_bookings_email ON tk_bookings (email)',
        'CREATE UNIQUE INDEX uq_users_username ON tk_users (username)',
        'CREATE INDEX ix_scan_booking ON tk_scan_log (booking_id)',
        'CREATE INDEX ix_attempts_bucket ON tk_attempts (bucket, created_at)',
    ];
    if (db_driver() === 'sqlite') {
        $indexes = array_map(
            static fn(string $s) => preg_replace('/^CREATE (UNIQUE )?INDEX /', 'CREATE $1INDEX IF NOT EXISTS ', $s),
            $indexes
        );
    }

    return array_merge($tables, $indexes);
}

function install_schema(): void
{
    foreach (schema_sql() as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            // MySQL has no "CREATE INDEX IF NOT EXISTS": ignore "duplicate key name".
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }
    foreach (settings_defaults() as $k => $v) {
        if (q_val('SELECT COUNT(*) FROM tk_settings WHERE k = ?', [$k]) == 0) {
            q('INSERT INTO tk_settings (k, v) VALUES (?, ?)', [$k, $v]);
        }
    }
}

// ---- Settings ---------------------------------------------------------------

function settings_defaults(): array
{
    return [
        'event_name'           => 'My Event',
        'event_tagline'        => '',
        'event_description'    => '',
        'event_datetime'       => (new DateTimeImmutable('+30 days'))->format('Y-m-d') . ' 19:00:00',
        'event_end_datetime'   => '',
        'event_venue'          => '',
        'event_map_embed'      => '',
        'event_map_link'       => '',
        'banner_url'           => '',
        'price_rupees'         => '499',
        'gate_price_rupees'    => '',
        'capacity'             => '200',
        'max_per_booking'      => '5',
        'sales_open'           => '0',
        'gate_sales_open'      => '0',
        'extra_field_label'    => '',
        'extra_field_required' => '0',
        'terms'                => 'Tickets are non-transferable. Entry is allowed once per ticket.',
        'accent_color'         => '#e8452c',
    ];
}

/** @return array<string,string> */
function settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = settings_defaults();
        foreach (q('SELECT k, v FROM tk_settings')->fetchAll() as $row) {
            $cache[$row['k']] = (string) $row['v'];
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    return settings()[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $updated = q('UPDATE tk_settings SET v = ? WHERE k = ?', [$value, $key])->rowCount();
    if ($updated === 0 && q_val('SELECT COUNT(*) FROM tk_settings WHERE k = ?', [$key]) == 0) {
        q('INSERT INTO tk_settings (k, v) VALUES (?, ?)', [$key, $value]);
    }
}
