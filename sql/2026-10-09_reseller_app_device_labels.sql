-- Self-contained, additive setup for named app activation codes.
-- Safe to run when the base app-device tables already exist or are missing.

CREATE TABLE IF NOT EXISTS reseller_device_activation_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  device_label VARCHAR(120) NULL,
  created_by_admin_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  consumed_device_id CHAR(36) NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_reseller_device_activation_hash (code_hash),
  KEY idx_reseller_device_activation (reseller_id, created_at),
  KEY idx_reseller_device_activation_state (reseller_id, consumed_at, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reseller_app_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  device_id CHAR(36) NOT NULL,
  device_name VARCHAR(120) NOT NULL,
  platform VARCHAR(16) NOT NULL,
  credential_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  last_ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_reseller_app_device_id (device_id),
  UNIQUE KEY uniq_reseller_app_device_credential (credential_hash),
  KEY idx_reseller_app_devices_owner (reseller_id, revoked_at, last_seen_at),
  KEY idx_reseller_app_devices_created (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reseller_device_activation_codes
  ADD COLUMN IF NOT EXISTS device_label VARCHAR(120) NULL AFTER code_hash;
