#!/usr/bin/env bash
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for file in api/*.php; do php -l "$file" >/dev/null; done
sed -n '/<script>/,/<\/script>/p' admin.html | sed '1d;$d' >/tmp/reseller-admin-security.js
sed -n '/<script>/,/<\/script>/p' index.html | sed '1d;$d' >/tmp/reseller-index-security.js
node --check /tmp/reseller-admin-security.js
node --check /tmp/reseller-index-security.js
composer validate --no-check-publish >/dev/null
git diff --check

if rg -n 'innerHTML|insertAdjacentHTML|outerHTML|eval\(|new Function|document\.write' index.html admin.html api/*.php; then
  echo "Unsafe DOM/eval pattern found" >&2
  exit 1
fi
rg -q "PWRSADMINSESSID" api/bootstrap.php
rg -q "PWRSRESELLERSESSID" api/bootstrap.php
rg -q "passkey_login_verify|passkey_registration_verify|passkey_remove" api/admin.php
rg -q "require_webauthn_runtime" api/admin.php
rg -q "PHP_VERSION_ID < 80401" api/admin.php api/webauthn.php
rg -q "owner_webauthn_challenges|owner_passkeys" sql/2026-08-27_owner_passkeys.sql
rg -q "Content-Security-Policy" .htaccess
rg -q "Strict-Transport-Security" .htaccess
rg -q "order_delivery_events" api/bootstrap.php api/order.php api/admin.php sql/2026-09-10_order_reliability.sql
rg -q "payment_notice_requests" api/bootstrap.php api/payment_notice.php api/admin.php sql/2026-09-10_order_reliability.sql
rg -q "arsenijee19@gmail.com" api/bootstrap.php api/payment_notice.php api/order.php
rg -q "support@licenca.rs" api/bootstrap.php api/payment_notice.php api/order.php
test -f vendor/autoload.php
test -f vendor/.htaccess
echo "security-static-ok"
