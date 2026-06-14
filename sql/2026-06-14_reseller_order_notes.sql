-- Adds reseller-owned internal notes and payment tracking per order.
-- Run in cPanel/phpMyAdmin after the admin panel migration.
-- Backward compatible; does not delete existing data.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS reseller_notes TEXT NULL,
  ADD COLUMN IF NOT EXISTS reseller_paid TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS reseller_paid_at DATETIME NULL;
