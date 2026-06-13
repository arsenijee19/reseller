# Project Context

## Project Overview
- Live reseller portal for PlayWorld.rs hosted on cPanel.
- Resellers log in with a token, create product orders for buyer emails, and spend wallet balance.
- Admins manage resellers, balances, product prices, and order delivery/status data.

## Current Project Status
- Completed functionality:
  - Reseller token login using `password_verify()` against `resellers.token_hash`.
  - Reseller balance lookup, product list, price list, order creation, recent order history.
  - Order creation writes `orders`, writes a negative `wallet_transactions` entry, updates reseller balance, sends notification email, and calls the n8n delivery webhook.
  - Admin login via `admin_users.password_hash`.
  - Admin panel at `/admin.html` for reseller balance/status/token changes, product create/update/deactivate/delete, order review/update, and schema visibility.
  - Reseller “Uplatio sam” button sends an email notification to `arsenijee19@gmail.com`.
- Partially implemented functionality:
  - Order delivery automation is still delegated to the existing n8n webhook.
  - Admin edits dynamic table columns, but the UI intentionally highlights the most important order fields.
- Unfinished work:
  - Run the SQL migration on cPanel/phpMyAdmin before using admin login.
  - Confirm live mail delivery from cPanel for `mail()`.
- Known limitations:
  - Local database was not available in this workspace, so database-backed flows need final live/staging validation after migration.
  - Existing database credentials are in `api/db.php` from the uploaded live project; do not duplicate them in docs or UI.
- Known bugs:
  - Unknown.
- Untested areas:
  - Actual cPanel MySQL migration execution.
  - Live email delivery.
  - n8n webhook response in production.

## File Structure
- `index.html` - reseller UI for login, orders, prices, balance, history, and payment notice.
- `admin.html` - admin UI for users, products, orders, and detected DB schema.
- `api/db.php` - PDO database connection.
- `api/bootstrap.php` - shared JSON responses, secure sessions, CSRF helpers, auth guards, DB schema helpers.
- `api/login.php` - reseller token login.
- `api/logout.php` - destroys reseller/admin session.
- `api/me.php` - current reseller profile/balance and CSRF token.
- `api/products.php` - active product list for reseller order form.
- `api/prices.php` - active price list for logged-in resellers.
- `api/order.php` - order creation, wallet charge, email notification, n8n webhook call.
- `api/orders.php` - reseller recent order history.
- `api/payment_notice.php` - reseller “Uplatio sam” email notification.
- `api/admin.php` - admin login/dashboard/update API.
- `api/topup.php` - admin-session-protected balance top-up endpoint.
- `sql/2026-06-13_admin_panel.sql` - migration for admin users and future delivery/status fields.

## Architecture & Technical Decisions
- Frameworks: no framework; plain PHP 8+, static HTML/CSS/JS.
- Database: MySQL/MariaDB via PDO.
- Authentication:
  - Resellers authenticate with token/password verified against `resellers.token_hash`.
  - Admin authenticates with `admin_users.password_hash`.
  - Sessions use HTTP-only cookies and `SameSite=Lax`; secure cookies are enabled when HTTPS is detected.
- CSRF:
  - Mutating reseller/admin POST requests use `X-CSRF-Token`.
- SQL safety:
  - New code uses prepared statements and whitelisted dynamic columns from `INFORMATION_SCHEMA`.
- XSS safety:
  - Updated frontend uses `textContent`/DOM APIs for DB-rendered values instead of injecting user data as HTML.
- Integrations:
  - `api/order.php` sends order emails and posts to `https://automation.psigre.rs/webhook/reseller-delivery`.
  - `api/payment_notice.php` emails `arsenijee19@gmail.com`.

## Setup & Execution
- Dependencies:
  - PHP 8+ with PDO MySQL and cURL enabled.
  - MySQL/MariaDB database with existing tables: `resellers`, `product_prices`, `orders`, `wallet_transactions`.
- Environment variables:
  - None currently used.
- Installation steps:
  - Upload the project folder contents to cPanel public web root.
  - Ensure `api/db.php` matches the cPanel database connection.
  - Run `sql/2026-06-13_admin_panel.sql` in phpMyAdmin.
- Run commands:
  - Static/PHP project; on cPanel it runs directly through Apache/PHP.
  - Local syntax check: `for f in api/*.php; do php -l "$f"; done`
- Build commands:
  - None.
- Test commands:
  - PHP syntax check above.

## Important Business Logic
- Reseller orders:
  - Product price is loaded from `product_prices` by `product_id`.
  - Currency must be `RSD`.
  - New order creates a random `request_id`.
  - Wallet transaction type `ORDER` is inserted with negative amount.
  - Reseller balance is decreased by product price.
- Admin balance changes:
  - Admin can set exact `balance_rsd` per reseller.
  - Balance differences are recorded as `ADMIN_ADJUSTMENT` wallet transactions.
- Product availability:
  - Migration adds `product_prices.status`; reseller-facing product and price APIs only show `status='active'` when this column exists.
  - Admin “Deaktiviraj” sets `status='inactive'` when available.
- Admin password:
  - Initial admin username is `admin`.
  - Initial password is `ArsoArso2026`, stored only as a bcrypt hash in the SQL migration.
  - Change it later by updating `admin_users.password_hash` with a new `password_hash()` value or by adding an admin password-change flow.

## Recent Changes
- Initial live project snapshot was committed and pushed to GitHub before modifications.
- Added shared PHP security/bootstrap helpers.
- Added admin API and `/admin.html`.
- Added SQL migration for `admin_users`, product status/availability/type/admin notes, order status/admin notes/delivery payload, and update timestamps.
- Added reseller “Uplatio sam” flow.
- Refreshed reseller UI for cleaner desktop/mobile layout and safer DOM rendering.
- Replaced the old URL-key `topup.php` flow with admin-session/CSRF protection.

## Current Priorities
- Run `sql/2026-06-13_admin_panel.sql` on the live cPanel database.
- Validate admin login and all admin edit flows on live/staging data.
- Confirm `mail()` delivery for order and payment-notice emails.
- Add an admin password-change screen.
- Consider moving DB credentials out of `api/db.php` into cPanel environment/config outside web root.

## Known Issues
- Local workspace has no access to the production database, so functional DB tests could not be completed locally.
- `mail()` returns only a boolean and does not guarantee inbox delivery.
- Hard deletes of products can affect historical order readability; prefer deactivation unless deletion is intentional.

## LLM Handoff Notes
- Read first:
  - `PROJECT_CONTEXT.md`
  - `api/bootstrap.php`
  - `api/admin.php`
  - `api/order.php`
  - `index.html`
  - `admin.html`
- Important assumptions:
  - Existing reseller login is token-based even when user-facing text says password/token.
  - `product_prices.product_id` is the stable product identifier used by orders.
  - cPanel serves the project over HTTPS in production.
- Fragile areas:
  - Database schema may differ slightly from inferred columns; admin API reads `INFORMATION_SCHEMA` to reduce hardcoding.
  - n8n webhook and email side effects happen after DB commit in `api/order.php`.
- Project-specific conventions:
  - Keep PHP endpoint responses as JSON with `ok`.
  - Use PDO prepared statements.
  - Do not show password/token hashes in UI.
  - Do not store secrets, customer data, or credentials in documentation.
- Common mistakes to avoid:
  - Do not bypass CSRF on mutating admin/reseller actions.
  - Do not hardcode product lists in frontend.
  - Do not delete old products when deactivation is enough.
