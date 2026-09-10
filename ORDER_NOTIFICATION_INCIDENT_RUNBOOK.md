# Order Notification Incident Runbook

Use this checklist when a reseller says that an order or “Uplatio sam” notice was not received.

## What this application accepts

- There is no public card checkout, Stripe, MerchantPro, or anonymous order endpoint in this repository.
- A financial order requires a valid reseller session, an active product, and enough reseller balance.
- “Uplatio sam” is only an informational notice. It does not verify a bank transfer and does not add balance.

## 1. Preserve the evidence

1. Do not delete orders, wallet rows, logs, or the reseller account.
2. Export a database backup from cPanel/phpMyAdmin before making manual corrections.
3. Note the approximate local time, reseller email, product, amount, and any bank-transfer reference supplied by the customer.

## 2. Deploy the reliability code

1. Deploy the latest `main` branch through cPanel Git Version Control.
2. Confirm that the deployment copied `api/*.php`, `admin.html`, `index.html`, and `sql/2026-09-10_order_reliability.sql`.
3. Keep the private `api/config.local.php`; it must not be replaced by Git deployment.
4. Run `sql/2026-09-10_order_reliability.sql` in phpMyAdmin. It is additive and does not delete existing data.

## 3. Find an order from a date

Use the server/database timezone consistently. Replace the dates with the incident date and the following date:

```sql
SELECT
  o.id,
  o.request_id,
  o.reseller_id,
  o.reseller_email,
  o.product_id,
  o.price_rsd,
  o.status,
  o.created_at
FROM orders o
WHERE o.created_at >= '2026-09-09 00:00:00'
  AND o.created_at <  '2026-09-10 00:00:00'
ORDER BY o.created_at DESC, o.id DESC;
```

For a found order, confirm the wallet charge:

```sql
SELECT id, reseller_id, type, amount_rsd, description, related_order_id, created_at
FROM wallet_transactions
WHERE related_order_id = YOUR_ORDER_ID
ORDER BY id ASC;
```

A normal order has one negative `ORDER` wallet transaction linked to the order. Do not manually insert a second charge.

## 4. Check notifications

```sql
SELECT order_id, event_type, status, recipients, http_status, attempts, error_message, created_at, updated_at
FROM order_delivery_events
WHERE order_id = YOUR_ORDER_ID
ORDER BY event_type;
```

For “Uplatio sam” clicks:

```sql
SELECT id, reseller_id, reseller_email, balance_rsd, clicked_at, status, recipients, attempts, error_message
FROM payment_notice_requests
WHERE clicked_at >= '2026-09-09 00:00:00'
  AND clicked_at <  '2026-09-10 00:00:00'
ORDER BY clicked_at DESC, id DESC;
```

The admin panel exposes the same information under **Porudžbine** and **Uplate**. Failed order emails and failed payment notices have a protected resend action.

## 5. Verify cPanel delivery

- Check cPanel **Email Deliverability** for the sending domain.
- Check **Track Delivery** / Exim mail logs for the recipient and incident time.
- Confirm SPF, DKIM, and DMARC for the sender domain.
- Use a real domain mailbox in `mail.from`, not a placeholder such as `example.com`.
- Remember that `mail()` returning `true` means only that the local mail server accepted the message; it does not prove Gmail or the support mailbox delivered it.

## 6. Verify n8n delivery

- Confirm the production webhook URL is present only in private server configuration.
- Confirm the n8n workflow is active and the webhook is the production URL, not the test URL.
- Check the n8n execution history for the order `request_id` or `order_db_id`.
- Check the Telegram node execution and credentials.
- If n8n was unavailable, the order and wallet charge remain authoritative; resend the operational email from Admin and resolve delivery in n8n without charging the reseller again.

## 7. Final reconciliation

1. Match the customer’s payment reference against the bank/provider record.
2. Match it to the reseller and the order amount.
3. Only after manual verification, update the reseller balance through the audited Admin balance action.
4. Never “fix” a missing email by creating a duplicate order.
5. Record the resolution internally without storing card data, tokens, passwords, or secrets.
