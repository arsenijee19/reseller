-- Admin panel migration for reseller.psigre.rs
-- Run in cPanel/phpMyAdmin on the existing database. It is backward compatible
-- and does not delete existing data.

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create the first admin through api/config.local.php:
-- set admin.username and admin.password_hash, then open /admin.html and log in.
-- The app will seed the admin row if it does not already exist.

ALTER TABLE product_prices
  ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS availability VARCHAR(80) NOT NULL DEFAULT 'available',
  ADD COLUMN IF NOT EXISTS product_type VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS status VARCHAR(40) NOT NULL DEFAULT 'new',
  ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL,
  ADD COLUMN IF NOT EXISTS delivery_payload TEXT NULL,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE resellers
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;
