#!/usr/bin/env bash
# Run the ticketing app locally (SQLite, no MySQL needed).
# Usage: ./start-local.sh   then open http://localhost:8080/install.php
set -e
cd "$(dirname "$0")"
command -v php >/dev/null || { echo "PHP is not installed. Mac: brew install php · Linux: sudo apt install php-cli php-sqlite3 php-gd php-curl php-mbstring"; exit 1; }
for ext in pdo_sqlite gd curl mbstring openssl; do
  php -m | grep -qi "^$ext$" || { echo "Missing PHP extension: $ext"; exit 1; }
done
if [ ! -f app/config.php ]; then
  cat > app/config.php <<'PHP'
<?php
// Local development config (created by start-local). Replace for production.
$c = require __DIR__ . '/config.sample.php';
$c['base_url'] = 'http://localhost:8080';
$c['db']['driver'] = 'sqlite';
$c['debug'] = true;
// Add your Razorpay TEST keys here to try payments:
// $c['razorpay']['key_id'] = 'rzp_test_...'; $c['razorpay']['key_secret'] = '...';
return $c;
PHP
  echo "Created app/config.php for local use."
fi
echo ""
echo "  Ticketing app running at http://localhost:8080"
echo "  First time? Open http://localhost:8080/install.php"
echo "  Press Ctrl+C to stop."
echo ""
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080
