-- Reseller settings: "Praćenje plaćanja" and "Obavesti me o svakoj porudžbini".
-- Additive only. The portal adds these columns itself when missing; run this once in phpMyAdmin
-- only if the DB user has no ALTER rights.
ALTER TABLE resellers
  ADD COLUMN pref_paid_tracking TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN order_notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN order_notify_email VARCHAR(255) NULL;
