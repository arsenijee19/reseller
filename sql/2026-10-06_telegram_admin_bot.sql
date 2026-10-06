-- Telegram administration integration. Additive only; existing account/order rows remain intact.
-- Run once in cPanel/phpMyAdmin after the wallet transaction type migration.

ALTER TABLE wallet_transactions
  ADD COLUMN source VARCHAR(24) NOT NULL DEFAULT 'panel',
  ADD COLUMN admin_id INT UNSIGNED NULL,
  ADD COLUMN admin_chat_id BIGINT NULL,
  ADD COLUMN balance_before_rsd INT NULL,
  ADD COLUMN balance_after_rsd INT NULL,
  ADD COLUMN telegram_message_id BIGINT NULL,
  ADD COLUMN idempotency_key VARCHAR(80) NULL,
  ADD COLUMN reversal_of_transaction_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uniq_wallet_idempotency (idempotency_key);
ALTER TABLE wallet_transactions
  ADD UNIQUE KEY uniq_wallet_reversal (reversal_of_transaction_id);

ALTER TABLE payment_notice_requests
  ADD COLUMN amount_rsd INT NULL,
  ADD COLUMN review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  ADD COLUMN reviewed_at DATETIME NULL,
  ADD COLUMN reviewed_by_admin_id INT UNSIGNED NULL,
  ADD COLUMN review_reason VARCHAR(255) NULL;

ALTER TABLE missing_game_reports
  ADD COLUMN resolved_at DATETIME NULL,
  ADD COLUMN resolved_by_admin_id INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS telegram_bot_config (
  id TINYINT UNSIGNED NOT NULL,
  api_token_hash VARCHAR(255) NULL,
  totp_threshold_rsd INT UNSIGNED NOT NULL DEFAULT 50000,
  low_balance_threshold_rsd INT NOT NULL DEFAULT 0,
  notifications_json TEXT NOT NULL,
  webhook_last_seen_at DATETIME NULL,
  outbox_last_seen_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO telegram_bot_config (id, notifications_json)
VALUES (1, '{"payment_notice":true,"new_order":true,"missing_game":true,"game_request":true,"low_balance":true,"inventory_error":true}');

CREATE TABLE IF NOT EXISTS telegram_admins (
  chat_id BIGINT NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NULL,
  added_by_admin_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id),
  KEY idx_telegram_admin_user (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_pending_actions (
  action_id CHAR(32) NOT NULL,
  chat_id BIGINT NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  preview_json MEDIUMTEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  totp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  telegram_message_id BIGINT NULL,
  result_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (action_id),
  KEY idx_telegram_action_expiry (status, expires_at),
  KEY idx_telegram_action_chat (chat_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key VARCHAR(120) NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  delivered_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_telegram_event (event_key),
  KEY idx_telegram_outbox_ready (status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_updates (
  update_id BIGINT NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (update_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_conversations (
  chat_id BIGINT NOT NULL,
  state VARCHAR(32) NOT NULL,
  payload_json TEXT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id),
  KEY idx_telegram_conversation_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reseller_game_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  suggestion VARCHAR(1000) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by_admin_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_game_request_status (status, created_at),
  KEY idx_game_request_reseller (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
