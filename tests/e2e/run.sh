#!/usr/bin/env bash
# End-to-end session/device tests against a throw-away MariaDB and PHP built-in server.
# Needs: mariadbd, mariadb-install-db, php (pdo_mysql), python3.
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
PORT=${PORT:-8099}
DBPORT=${DBPORT:-33306}
cleanup() {
  [ -n "${PHP_PID:-}" ] && kill "$PHP_PID" 2>/dev/null || true
  [ -n "${DB_PID:-}" ] && kill "$DB_PID" 2>/dev/null || true
  sleep 1; rm -rf "$WORK"
}
trap cleanup EXIT

mariadb-install-db --user=root --datadir="$WORK/data" --auth-root-authentication-method=normal >/dev/null 2>&1
mariadbd --user=root --datadir="$WORK/data" --socket="$WORK/db.sock" --port=$DBPORT --bind-address=127.0.0.1 --pid-file="$WORK/db.pid" >"$WORK/db.log" 2>&1 &
DB_PID=$!
for i in $(seq 1 60); do mariadb --socket="$WORK/db.sock" -uroot -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
mariadb --socket="$WORK/db.sock" -uroot -e "CREATE DATABASE pw; CREATE USER 'pw'@'127.0.0.1' IDENTIFIED BY 'pw'; GRANT ALL ON pw.* TO 'pw'@'127.0.0.1';"
mariadb --socket="$WORK/db.sock" -uroot pw < "$ROOT/tests/e2e/schema.sql"
for f in 2026-10-09_reseller_app_devices.sql 2026-10-09_reseller_app_device_labels.sql; do mariadb --socket="$WORK/db.sock" -uroot pw < "$ROOT/sql/$f"; done

mkdir -p "$WORK/www"
cp -r "$ROOT/api" "$ROOT/vendor" "$WORK/www/" 2>/dev/null || cp -r "$ROOT/api" "$WORK/www/"
cp "$ROOT/index.html" "$ROOT/admin.html" "$ROOT/manifest.webmanifest" "$WORK/www/"
cp -r "$ROOT/assets" "$ROOT/downloads" "$WORK/www/"
ADMIN_HASH=$(php -r 'echo password_hash("AdminPass-12345", PASSWORD_DEFAULT);')
cat > "$WORK/www/api/config.local.php" <<PHP
<?php
return [
  'app' => ['timezone' => 'Europe/Belgrade'],
  'db' => ['host' => '127.0.0.1:$DBPORT', 'name' => 'pw', 'user' => 'pw', 'pass' => 'pw', 'charset' => 'utf8mb4'],
  'admin' => ['username' => 'admin', 'password_hash' => '$ADMIN_HASH'],
  'security' => ['encryption_key' => '$(head -c 32 /dev/urandom | base64)', 'origin' => 'http://127.0.0.1:$PORT'],
  'mail' => ['from' => 'no-reply@example.test', 'payment_notice_to' => 'a@example.test'],
];
PHP
# db.host with port is not supported by the DSN builder, so pass host/port separately via env-free config
sed -i "s|'host' => '127.0.0.1:$DBPORT'|'host' => '127.0.0.1;port=$DBPORT'|" "$WORK/www/api/config.local.php"
php -S 127.0.0.1:$PORT -t "$WORK/www" >"$WORK/php.log" 2>&1 &
PHP_PID=$!
sleep 1
WWW="$WORK/www" BASE="http://127.0.0.1:$PORT" DB_SOCK="$WORK/db.sock" python3 "$ROOT/tests/e2e/test_sessions.py"
if [ "${SKIP_PWA:-0}" != "1" ] && command -v node >/dev/null; then
  mkdir -p "${SHOTS:-/tmp/pw-e2e-shots}"
  SHOTS="${SHOTS:-/tmp/pw-e2e-shots}" BASE="http://127.0.0.1:$PORT" DB_SOCK="$WORK/db.sock" node "$ROOT/tests/e2e/pwa.js"
fi
