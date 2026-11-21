-- Private buyer/account records per reseller ("Podaci kupca" on orders, "Nova evidencija").
-- payload holds the fields as encrypted JSON (same key as 2-step secrets); nothing here is shown to admins.
-- The portal creates the table itself; run this once in phpMyAdmin only if the DB user lacks CREATE rights.
CREATE TABLE IF NOT EXISTS reseller_customer_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_customer_record_order (reseller_id, order_id),
  KEY idx_customer_record_reseller (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
