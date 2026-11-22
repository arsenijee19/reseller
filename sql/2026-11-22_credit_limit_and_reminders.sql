-- Allowed minus per reseller + weekly balance reminder log. Additive only; the portal creates these
-- itself. Run once in phpMyAdmin only if the DB user lacks ALTER/CREATE rights.
ALTER TABLE resellers
  ADD COLUMN credit_limit_rsd INT UNSIGNED NULL,
  ADD COLUMN credit_locked_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS balance_reminder_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_key VARCHAR(40) NOT NULL,
  reseller_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  balance_rsd INT NOT NULL,
  status VARCHAR(20) NOT NULL,
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_balance_reminder_run (run_key, reseller_id),
  KEY idx_balance_reminder_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
