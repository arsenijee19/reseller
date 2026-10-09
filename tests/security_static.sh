#!/usr/bin/env bash
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

for file in api/*.php; do php -l "$file" >/dev/null; done
php tests/test_device_auth_helpers.php >/dev/null
sed -n '/<script>/,/<\/script>/p' admin.html | sed '1d;$d' >/tmp/reseller-admin-security.js
sed -n '/<script>/,/<\/script>/p' index.html | sed '1d;$d' >/tmp/reseller-index-security.js
node --check /tmp/reseller-admin-security.js
node --check /tmp/reseller-index-security.js
for f in assets/*.js; do node --check "$f"; done
composer validate --no-check-publish >/dev/null
git diff --check

if rg -n '(^|[^.[:alnum:]_])text\(' index.html; then
  echo "Undefined bare text() helper call found in index.html" >&2
  exit 1
fi
rg -q 'Promise\.all\(\[loadCatalog\(\), loadOrders\(\)\]\)' index.html
rg -q 'failure\.panelLoad = true' index.html
rg -q 'cache:"no-store"' index.html
rg -q 'discountPercent' index.html api/prices.php api/order.php
rg -q 'recommendedResalePrice' index.html
rg -q 'shop-price-tip' index.html
rg -q "LIMIT 10" api/orders.php
rg -q 'add_transaction' api/admin.php admin.html
rg -q 'wallet_transactions' api/admin.php admin.html
rg -q 'reseller_device_activation_codes' api/device_auth.php api/admin.php sql/2026-10-09_reseller_app_devices.sql
rg -q 'device_label' api/admin.php admin.html sql/2026-10-09_reseller_app_device_labels.sql tests/test_device_auth_helpers.php
test -f sql/2026-10-09_reseller_app_device_labels.sql
rg -q 'AndroidKeyStore' mobile/android/app/src/main/java/rs/playworld/reseller/MainActivity.kt
rg -q 'PlayWorldNative.logout' index.html

# Theme buttons use fixed, source-controlled SVG strings; reject other unsafe DOM/eval APIs.
if rg -n 'insertAdjacentHTML|outerHTML|eval\(|new Function|document\.write' index.html admin.html assets/*.js api/*.php; then
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
rg -q "discount_percent" api/bootstrap.php api/prices.php api/order.php api/admin.php index.html admin.html
test -f sql/2026-10-06_reseller_discounts.sql
rg -q "arsenijee19@gmail.com" api/bootstrap.php api/payment_notice.php api/order.php
rg -q "support@licenca.rs" api/bootstrap.php api/payment_notice.php api/order.php
test -f vendor/autoload.php
test -f vendor/.htaccess
echo "security-static-ok"
