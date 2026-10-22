#!/usr/bin/env bash
# Run the ticketing app locally (SQLite, no MySQL needed).
# Usage: ./start-local.sh   then open http://localhost:8080/install.php
set -e
cd "$(dirname "$0")"
command -v php >/dev/null || { echo "PHP is not installed. Mac: brew install php · Linux: sudo apt install php-cli php-sqlite3 php-gd php-curl php-mbstring"; exit 1; }
for ext in pdo_sqlite curl mbstring openssl; do
  php -m | grep -qi "^$ext$" || { echo "Missing PHP extension: $ext"; exit 1; }
done
php -m | grep -qi "^gd$" || echo "Note: PHP GD extension not found. QR codes use SVG locally (production needs GD for email/WhatsApp images)."
# In GitHub Codespaces, use the forwarded https address so links and QR codes work
if [ -n "$CODESPACE_NAME" ] && [ -n "$GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN" ]; then
  BASE="https://$CODESPACE_NAME-8080.$GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN"
else
  BASE="http://localhost:8080"
fi
if [ ! -f app/config.php ]; then
  cat > app/config.php <<'PHP'
<?php
// Local development config (created by start-local). Replace for production.
$c = require __DIR__ . '/config.sample.php';
$c['base_url'] = '__BASE__';
$c['db']['driver'] = 'sqlite';
$c['debug'] = true;
// Add your Razorpay TEST keys here to try payments:
// $c['razorpay']['key_id'] = 'rzp_test_...'; $c['razorpay']['key_secret'] = '...';
return $c;
PHP
  sed -i.bak "s|__BASE__|$BASE|" app/config.php && rm -f app/config.php.bak
  echo "Created app/config.php for local use."
fi
echo ""
echo "  Ticketing app running at $BASE"
echo "  First time? Open $BASE/install.php"
echo "  Press Ctrl+C to stop."
echo ""
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080
