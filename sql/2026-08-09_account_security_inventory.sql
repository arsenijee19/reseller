-- Account security, Inventory verification-code API audit, and missing-game reports.
-- Run after the existing admin/order notes migrations. Backward compatible; does not delete data.

ALTER TABLE resellers
  ADD COLUMN IF NOT EXISTS phone VARCHAR(32) NULL,
  ADD COLUMN IF NOT EXISTS profile_completed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS credential_changed_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS security_2fa_reminded_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS security_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type VARCHAR(40) NOT NULL DEFAULT 'reseller',
  actor_id INT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  result VARCHAR(40) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  metadata_json TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_security_actor (actor_type, actor_id, created_at),
  KEY idx_security_event (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type VARCHAR(40) NOT NULL DEFAULT 'reseller',
  identifier_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_identifier (actor_type, identifier_hash, created_at),
  KEY idx_login_ip (actor_type, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_api_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  account_email VARCHAR(255) NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  order_reference VARCHAR(100) NULL,
  external_request_id VARCHAR(120) NOT NULL,
  idempotency_fingerprint CHAR(64) NOT NULL,
  inventory_request_id VARCHAR(120) NULL,
  http_status INT NULL,
  inventory_status VARCHAR(80) NULL,
  email_status VARCHAR(80) NULL,
  duplicate TINYINT(1) NOT NULL DEFAULT 0,
  result VARCHAR(40) NOT NULL DEFAULT 'created',
  error_message VARCHAR(500) NULL,
  request_sent_at DATETIME NULL,
  response_received_at DATETIME NULL,
  duration_ms INT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_inventory_external_request (external_request_id),
  UNIQUE KEY uniq_inventory_idempotency (idempotency_fingerprint),
  KEY idx_inventory_reseller (reseller_id, created_at),
  KEY idx_inventory_account_day (reseller_id, account_email, created_at),
  KEY idx_inventory_status (result, http_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS missing_game_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'open',
  notification_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  notification_message_id VARCHAR(120) NULL,
  notification_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_missing_game_order (order_id),
  KEY idx_missing_game_reseller (reseller_id, created_at),
  KEY idx_missing_game_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
