<?php
declare(strict_types=1);

/*
 * Loaded by every public PHP file. Sets up the autoloader, config, errors,
 * session and shared helpers.
 */

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

// ---- PSR-4 autoloader for the bundled libraries in app/lib ---------------
spl_autoload_register(static function (string $class): void {
    static $map = [
        'PHPMailer\\PHPMailer\\' => APP_DIR . '/lib/PHPMailer/',
        'chillerlan\\QRCode\\'   => APP_DIR . '/lib/chillerlan/QRCode/',
        'chillerlan\\Settings\\' => APP_DIR . '/lib/chillerlan/Settings/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// ---- Config ----------------------------------------------------------------
if (!is_file(APP_DIR . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Setup needed: copy app/config.sample.php to app/config.php and fill in your details.\n");
}
try {
    $GLOBALS['CONFIG'] = require APP_DIR . '/config.php';
} catch (Throwable $e) {
    // A typo in config.php would otherwise give a blank HTTP 500.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("app/config.php has an error on line " . $e->getLine() . ":\n" . $e->getMessage()
        . "\n\nCheck that line for a missing quote ('), semicolon (;) or bracket, and use plain straight quotes.\n");
}
if (!is_array($GLOBALS['CONFIG'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("app/config.php must end with: return \$c;  (or return [ ... ];)\n");
}

function config(string $path, mixed $default = null): mixed
{
    $value = $GLOBALS['CONFIG'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

date_default_timezone_set((string) config('timezone', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

// ---- Errors: never shown in production, always logged ---------------------
$debug = (bool) config('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_DIR . '/logs/php-error.log');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    app_log('error', 'Uncaught: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $debug ? $e->getMessage() : 'Something went wrong. Please try again.']);
    } else {
        echo $debug
            ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>'
            : 'Something went wrong. Please try again.';
    }
});

function app_log(string $level, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] %s: %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents(APP_DIR . '/logs/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

// ---- Security headers (also set in .htaccess; PHP covers servers without it)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

require APP_DIR . '/db.php';
require APP_DIR . '/helpers.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/razorpay.php';
require APP_DIR . '/tickets.php';
require APP_DIR . '/notify.php';
require APP_DIR . '/layout.php';

// ---- Session (only started on pages that need it, see start_session()) ---
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = str_starts_with((string) config('base_url'), 'https://');
    session_name('tksess');
    session_set_cookie_params([
        'lifetime' => 16 * 3600, // survives closing the browser on event day
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Staff stay signed in for the whole event day.
    ini_set('session.gc_maxlifetime', (string) (16 * 3600));
    ini_set('session.use_strict_mode', '1');
    session_start();
}
