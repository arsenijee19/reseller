-- Owner-only WebAuthn passkeys. Apply once on production.
CREATE TABLE IF NOT EXISTS owner_webauthn_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id_hash CHAR(64) NOT NULL,
  ceremony VARCHAR(24) NOT NULL,
  challenge VARCHAR(255) NOT NULL,
  options_json MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_owner_webauthn_challenge (challenge),
  KEY idx_owner_webauthn_challenge_session (session_id_hash, ceremony, used_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_passkeys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  credential_id VARCHAR(1024) NOT NULL,
  credential_record_json MEDIUMTEXT NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT 'Passkey',
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_owner_passkey_credential (credential_id),
  KEY idx_owner_passkey_admin (admin_id, revoked_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
