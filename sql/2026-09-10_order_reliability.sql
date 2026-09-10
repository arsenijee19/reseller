-- Adds durable order/email/webhook and payment-notice observability.
-- Run in cPanel/phpMyAdmin after the existing project migrations.
-- Backward compatible; it does not delete or rewrite existing orders.

CREATE TABLE IF NOT EXISTS order_delivery_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  recipients VARCHAR(1000) NULL,
  http_status INT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NULL,
  payload_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_order_delivery_event (order_id, event_type),
  KEY idx_order_delivery_status (status, updated_at),
  KEY idx_order_delivery_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_notice_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  reseller_email VARCHAR(255) NOT NULL,
  balance_rsd INT NOT NULL DEFAULT 0,
  clicked_at DATETIME NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  recipients VARCHAR(1000) NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payment_notice_status (status, created_at),
  KEY idx_payment_notice_reseller (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
