-- Admin app activation codes and revocable admin device credentials.
-- Additive only. The API also creates these tables on first use, so running this file is optional.

CREATE TABLE IF NOT EXISTS admin_device_activation_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  device_label VARCHAR(120) NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  consumed_device_id CHAR(36) NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_device_activation_hash (code_hash),
  KEY idx_admin_device_activation_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_app_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
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
  UNIQUE KEY uniq_admin_app_device_id (device_id),
  UNIQUE KEY uniq_admin_app_device_credential (credential_hash),
  KEY idx_admin_app_devices_owner (admin_id, revoked_at, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
