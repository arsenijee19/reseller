-- Adds reseller-owned internal notes per order.
-- Run in cPanel/phpMyAdmin after the admin panel migration.
-- Backward compatible; does not delete existing data.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS reseller_notes TEXT NULL;
