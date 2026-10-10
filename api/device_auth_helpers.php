<?php
declare(strict_types=1);

const APP_ACTIVATION_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function app_activation_code(): string {
  $bytes = random_bytes(12);
  $alphabet = APP_ACTIVATION_CODE_ALPHABET;
  $code = '';
  for ($i = 0; $i < 12; $i++) {
    $code .= $alphabet[ord($bytes[$i]) & 31];
  }
  return $code;
}

function normalize_app_activation_code(string $code): string {
  $code = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
  return preg_match('/^[A-HJ-NP-Z2-9]{12}$/', $code) ? $code : '';
}

function normalize_app_device_label(string $label): string {
  $label = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label);
  if (!is_string($label) || preg_match('//u', $label) !== 1) return '';
  $label = preg_replace('/\s+/u', ' ', trim($label));
  if (!is_string($label)) return '';
  if (function_exists('mb_substr')) return mb_substr($label, 0, 120, 'UTF-8');
  $characters = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
  return is_array($characters) ? implode('', array_slice($characters, 0, 120)) : '';
}

function app_device_credential_hash(string $credential): string {
  return hash('sha256', $credential);
}

// Admin app devices: one-time codes issued from the admin panel, used to sign the
// Android app in as the admin. Kept separate from the reseller device tables.
function ensure_admin_app_device_tables(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  if (schema_ensured('ensure_admin_app_device_tables')) { $done = true; return; }
  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  schema_mark_ensured('ensure_admin_app_device_tables');
  $done = true;
}

const ADMIN_DEVICE_IDLE_DAYS = 30;

/** Admin devices that were not used for 30 days lose access and must be enrolled again. */
function revoke_idle_admin_devices(PDO $pdo): void {
  if (!table_exists($pdo, 'admin_app_devices')) return;
  $pdo->exec('UPDATE admin_app_devices SET revoked_at = NOW()
    WHERE revoked_at IS NULL AND COALESCE(last_seen_at, created_at) < (NOW() - INTERVAL ' . (int)ADMIN_DEVICE_IDLE_DAYS . ' DAY)');
}
