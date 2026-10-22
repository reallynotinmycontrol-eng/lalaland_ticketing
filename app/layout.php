<?php
declare(strict_types=1);

/**
 * Shared page chrome.
 * @param array{nav?:string, body_class?:string, head?:string, description?:string, og_image?:string} $opt
 *   nav: 'public' (default), 'staff', 'admin' or 'none'
 */
function render_header(string $title, array $opt = []): void
{
    $nav = $opt['nav'] ?? 'public';
    $accent = accent_color();
    $user = in_array($nav, ['staff', 'admin'], true) ? current_user() : null;
    $eventName = setting('event_name');
    $fullTitle = $title === $eventName || $title === '' ? $eventName : $title . ' · ' . $eventName;
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($fullTitle) ?></title>
<?php if (!empty($opt['description'])): ?>
<meta name="description" content="<?= e($opt['description']) ?>">
<meta property="og:title" content="<?= e($eventName) ?>">
<meta property="og:description" content="<?= e($opt['description']) ?>">
<?php endif; ?>
<?php if (!empty($opt['og_image'])): ?>
<meta property="og:image" content="<?= e($opt['og_image']) ?>">
<?php endif; ?>
<?php if ($nav !== 'public'): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<meta name="theme-color" content="<?= e($accent) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css?v=<?= e(asset_version('style.css')) ?>">
<style>:root{--accent:<?= e($accent) ?>}</style>
<?= $opt['head'] ?? '' ?>
</head>
<body class="<?= e($opt['body_class'] ?? '') ?>">
<?php if ($nav === 'staff' || $nav === 'admin'): ?>
<header class="topbar">
  <a class="brand" href="<?= $nav === 'admin' ? '/admin/' : '/scan.php' ?>"><?= e($eventName) ?></a>
  <nav class="topnav">
    <?php if ($nav === 'admin'): ?>
      <a href="/admin/">Dashboard</a>
      <a href="/admin/bookings.php">Bookings</a>
      <a href="/admin/settings.php">Event</a>
      <a href="/admin/staff.php">Staff</a>
    <?php endif; ?>
    <a href="/scan.php">Scanner</a>
    <a href="/boxoffice.php">Box office</a>
    <?php if ($nav === 'staff' && $user && $user['role'] === 'admin'): ?><a href="/admin/">Admin</a><?php endif; ?>
    <?php if ($user): ?><a href="/logout.php" class="muted">Sign out</a><?php endif; ?>
  </nav>
</header>
<?php foreach (take_flashes() as $f): ?>
<div class="flash flash-<?= e($f['t']) ?>"><?= e($f['m']) ?></div>
<?php endforeach; ?>
<?php endif;
}

function render_footer(array $opt = []): void
{
    $main = safe_url((string) config('main_site_url'));
    ?>
<?php if (($opt['nav'] ?? 'public') === 'public'): ?>
<footer class="site-footer">
  <span>© <?= date('Y') ?> <?= e((string) config('organiser_name')) ?></span>
  <?php if ($main): ?><a href="<?= e($main) ?>">Back to website</a><?php endif; ?>
</footer>
<?php endif; ?>
</body>
</html>
<?php
}

function asset_version(string $file): string
{
    $path = ROOT_DIR . '/assets/' . $file;
    return is_file($path) ? (string) filemtime($path) : '1';
}
