-- Adds auditable admin order cancellation/reversal metadata.
-- Backward compatible and non-destructive; run after the admin panel migration.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS canceled_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS canceled_by_admin_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL;
