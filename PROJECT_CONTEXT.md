# Project Context

## Project Overview
- Live reseller portal for PlayWorld.rs hosted on cPanel.
- Resellers log in with a token, create product orders for buyer emails, and spend wallet balance.
- Admins manage resellers, balances, product prices, and order delivery/status data.

## Current Project Status
- Completed functionality:
  - Reseller token login using `password_verify()` against `resellers.token_hash`.
  - Reseller balance lookup, product list, price list, order creation, recent order history.
  - Resellers can save internal notes and mark each order as internally paid/unpaid for their own tracking.
  - Reseller product search filters the order dropdown and price list by product details.
  - Order creation writes `orders`, writes a negative `wallet_transactions` entry, updates reseller balance, sends notification email, and calls the n8n delivery webhook.
  - Admin login via `admin_users.password_hash`.
  - Admin panel at `/admin.html` for reseller balance/status/token changes, product create/update/deactivate/delete, order review/update, and schema visibility.
  - Admin order view includes reseller-owned notes and internal paid markers when those columns exist.
  - Admin product table includes client-side search across product fields.
  - Reseller “Uplatio sam” button sends an email notification to the configured admin email.
  - Reseller “Zatraži igru” button opens a popup and emails the requested game suggestion to the admin.
  - Reseller order flow shows a confirmation dialog with product, account type, buyer email, and price before sending the order request/webhook.
  - Reseller order submission shows a lightweight progress bar while the backend creates the order.
  - Reseller login shows the portal immediately after authentication; balance/catalog load first and order history loads in the background.
  - Reseller UI restores an existing server session on page load, so refresh does not force a new login while the session is valid.
  - Reseller and admin UIs support light/dark mode with the selected theme stored locally in the browser.
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
- `api/db.php` - PDO database connection and configuration loader.
- `api/config.example.php` - safe template for local/private configuration.
- `api/config.local.php` - ignored private configuration file required on cPanel; never commit it.
- `.htaccess` and `api/.htaccess` - deny directory listing and block public access to docs, SQL files, logs, dotfiles, and private config.
- `api/bootstrap.php` - shared JSON responses, secure sessions, CSRF helpers, auth guards, DB schema helpers.
- `api/login.php` - reseller token login.
- `api/logout.php` - destroys reseller/admin session.
- `api/me.php` - current reseller profile/balance and CSRF token.
- `api/products.php` - active product list for reseller order form.
- `api/prices.php` - active price list for logged-in resellers.
- `api/order.php` - order creation, wallet charge, email notification, n8n webhook call.
- `api/orders.php` - reseller recent order history.
- `api/order_notes.php` - reseller-owned internal notes update endpoint for existing orders.
- `api/order_paid.php` - reseller-owned internal paid/unpaid marker endpoint for existing orders.
- `api/payment_notice.php` - reseller “Uplatio sam” email notification.
- `api/game_request.php` - reseller requested-game suggestion email notification.
- `api/admin.php` - admin login/dashboard/update API.
- `api/topup.php` - admin-session-protected balance top-up endpoint.
- `sql/2026-06-13_admin_panel.sql` - migration for admin users and future delivery/status fields.
- `sql/2026-06-14_reseller_order_notes.sql` - migration for reseller-owned order notes and internal paid markers.

## Architecture & Technical Decisions
- Frameworks: no framework; plain PHP 8+, static HTML/CSS/JS.
- Database: MySQL/MariaDB via PDO.
- Authentication:
  - Resellers authenticate with token/password verified against `resellers.token_hash`.
  - Admin authenticates with `admin_users.password_hash`.
  - Sessions use HTTP-only cookies and `SameSite=Lax`; secure cookies are enabled when HTTPS is detected.
  - Reseller/admin sessions expire after 60 minutes of inactivity.
- CSRF:
  - Mutating reseller/admin POST requests use `X-CSRF-Token`.
- SQL safety:
  - New code uses prepared statements and whitelisted dynamic columns from `INFORMATION_SCHEMA`.
- XSS safety:
  - Updated frontend uses `textContent`/DOM APIs for DB-rendered values instead of injecting user data as HTML.
- Integrations:
  - `api/order.php` sends order emails and posts to the configured n8n webhook.
  - n8n receives `request_id`, `order_db_id`, `reseller_email`, `product_id`, `product_name`, `account_type`, `price_rsd`, `currency`, `customer_email`, and timestamp.
  - Updated local n8n workflow export `/Users/arsoplayworld/Downloads/reseller.json` includes Telegram notifications for received orders and completed deliveries, with product, account type, and price included in the message.
  - `api/payment_notice.php` emails the configured payment notice recipient.

## Setup & Execution
- Dependencies:
  - PHP 8+ with PDO MySQL and cURL enabled.
  - MySQL/MariaDB database with existing tables: `resellers`, `product_prices`, `orders`, `wallet_transactions`.
- Environment variables:
  - Optional fallback keys use uppercase config paths, for example `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `ADMIN_PASSWORD_HASH`.
- Installation steps:
  - Upload the project folder contents to cPanel public web root.
  - Create `api/config.local.php` from `api/config.example.php` on cPanel and fill in private values.
  - Run `sql/2026-06-13_admin_panel.sql` in phpMyAdmin.
  - Run `sql/2026-06-14_reseller_order_notes.sql` in phpMyAdmin for per-order reseller notes.
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
  - Order status is updated to `pending_delivery`, then `delivered` or `delivery_failed` when the n8n webhook responds if the status columns exist.
  - n8n delivery can use product fields from the PHP payload, so newly added admin products do not require a hardcoded n8n map when sheet names match the product/account type.
  - Reseller order notes are stored in `orders.reseller_notes` and internal paid markers in `orders.reseller_paid` / `orders.reseller_paid_at`; both can only be updated by the reseller that owns the order.
- Admin balance changes:
  - Admin can set exact `balance_rsd` per reseller.
  - Balance differences are recorded as `ADMIN_ADJUSTMENT` wallet transactions.
- Product availability:
  - Migration adds `product_prices.status`; reseller-facing product and price APIs only show `status='active'` when this column exists.
  - Admin “Deaktiviraj” sets `status='inactive'` when available.
- Admin password:
  - Initial admin username and password hash are configured in ignored `api/config.local.php`.
  - If `admin_users` is empty, `api/admin.php` seeds the first admin from the private config.
  - Change it later by updating `admin_users.password_hash` with a new `password_hash()` value or by adding an admin password-change flow.

## Recent Changes
- Initial live project snapshot was committed and pushed to GitHub before modifications.
- Added shared PHP security/bootstrap helpers.
- Added admin API and `/admin.html`.
- Added SQL migration for `admin_users`, product status/availability/type/admin notes, order status/admin notes/delivery payload, and update timestamps.
- Added reseller “Uplatio sam” flow.
- Refreshed reseller UI for cleaner desktop/mobile layout and safer DOM rendering.
- Replaced the old URL-key `topup.php` flow with admin-session/CSRF protection.
- Removed production credentials, webhook URL, notification recipients, logs, and the initial admin hash from versioned source code.
- Added `.htaccess` hardening for files that should not be publicly served from cPanel.
- Added order confirmation, submit progress feedback, and light/dark mode.
- Added richer n8n delivery payload and order delivery status tracking.
- Refined admin panel layout, spacing, table actions, product form width, and mobile responsiveness.
- Updated the n8n workflow export to send Telegram order/delivery notifications with account type and price.
- Added client-side search to the admin products table.
- Added reseller-facing product search for order selection and price list filtering.
- Added an Admin panel link to the reseller login screen, refined reseller dropdown styling, shortened order notification emails, and optimized reseller login so past orders no longer block the initial portal display.
- Set reseller/admin session lifetime to 60 minutes.
- Fixed reseller catalog loading so products and prices render together after both API calls return, preventing an empty price list when responses arrive out of order.
- Added reseller session restore on page load and refreshed the 60-minute session cookie on active requests.
- Added reseller requested-game popup and email notification flow.
- Added reseller-owned order notes with a backward-compatible SQL migration.
- Added reseller-owned internal paid/unpaid markers per order with confirmation before changing state.
- Refined reseller order cards with alternating backgrounds and paid/unpaid color states.

## Current Priorities
- Run pending SQL migrations on the live cPanel database, including `sql/2026-06-13_admin_panel.sql` and `sql/2026-06-14_reseller_order_notes.sql`.
- Validate admin login and all admin edit flows on live/staging data.
- Confirm `mail()` delivery for order and payment-notice emails.
- Add an admin password-change screen.
- Rotate the database password and n8n webhook because earlier commits contained those values.

## Known Issues
- Local workspace has no access to the production database, so functional DB tests could not be completed locally.
- `mail()` returns only a boolean and does not guarantee inbox delivery.
- Hard deletes of products can affect historical order readability; prefer deactivation unless deletion is intentional.
- Git history previously contained production DB credentials and webhook URL. The latest source removes them, but the live secrets should still be rotated.

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
- `api/config.local.php` must exist on cPanel or equivalent environment variables must be set.
  - Upload `api/config.local.php` to cPanel, but never push it to GitHub.
- Fragile areas:
  - Database schema may differ slightly from inferred columns; admin API reads `INFORMATION_SCHEMA` to reduce hardcoding.
  - n8n webhook and email side effects happen after DB commit in `api/order.php`.
- Project-specific conventions:
  - Keep PHP endpoint responses as JSON with `ok`.
  - Use PDO prepared statements.
  - Do not show password/token hashes in UI.
  - Do not store secrets, customer data, or credentials in documentation.
  - Never commit `api/config.local.php` or runtime logs.
- Common mistakes to avoid:
  - Do not bypass CSRF on mutating admin/reseller actions.
  - Do not hardcode product lists in frontend.
  - Do not delete old products when deactivation is enough.
