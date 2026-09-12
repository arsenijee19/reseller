<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SESSION_LIFETIME_SECONDS = 3600;
const SESSION_ABSOLUTE_LIFETIME_SECONDS = 28800;
const MAX_JSON_BODY_BYTES = 65536;

function json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  header('Pragma: no-cache');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json_body(): array {
  $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($length > MAX_JSON_BODY_BYTES) {
    json_response(['ok' => false, 'error' => 'Zahtev je prevelik.'], 413);
  }
  $raw = file_get_contents('php://input');
  if ($raw === false || strlen($raw) > MAX_JSON_BODY_BYTES) {
    json_response(['ok' => false, 'error' => 'Zahtev je prevelik.'], 413);
  }
  try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    json_response(['ok' => false, 'error' => 'Zahtev nije validan JSON.'], 400);
  }
  return is_array($input) ? $input : [];
}

function start_secure_session(string $scope = 'reseller'): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  $scope = $scope === 'admin' ? 'admin' : 'reseller';
  session_name($scope === 'admin' ? 'PWRSADMINSESSID' : 'PWRSRESELLERSESSID');
  ini_set('session.gc_maxlifetime', (string)SESSION_ABSOLUTE_LIFETIME_SECONDS);

  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime' => SESSION_ABSOLUTE_LIFETIME_SECONDS,
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Strict',
    'path' => '/',
  ]);
  session_start();

  if (ini_get('session.use_cookies') && session_id() !== '') {
    setcookie(session_name(), session_id(), [
      'expires' => time() + SESSION_ABSOLUTE_LIFETIME_SECONDS,
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Strict',
    ]);
  }

  $now = time();
  $createdAt = (int)($_SESSION['created_at'] ?? 0);
  $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
  if (($createdAt > 0 && ($now - $createdAt) > SESSION_ABSOLUTE_LIFETIME_SECONDS)
    || ($lastActivity > 0 && ($now - $lastActivity) > SESSION_LIFETIME_SECONDS)) {
    $_SESSION = [];
    session_regenerate_id(true);
    $createdAt = $now;
  }
  $_SESSION['created_at'] = $createdAt ?: $now;
  $_SESSION['last_activity'] = $now;
}

function require_same_origin(): void {
  $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
  $expected = rtrim((string)config_value('security.origin', 'https://reseller.psigre.rs'), '/');
  if ($origin !== '' && rtrim($origin, '/') !== $expected) {
    json_response(['ok' => false, 'error' => 'Nedozvoljen izvor zahteva.'], 403);
  }
  $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
  if (in_array($fetchSite, ['cross-site', 'same-site'], true) && $fetchSite === 'cross-site') {
    json_response(['ok' => false, 'error' => 'Nedozvoljen izvor zahteva.'], 403);
  }
}

function require_json_content_type(): void {
  $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
  if ($contentType !== '' && strpos($contentType, 'application/json') !== 0) {
    json_response(['ok' => false, 'error' => 'Content-Type mora biti application/json.'], 415);
  }
}

function csrf_token(): string {
  start_secure_session();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function require_csrf(): void {
  start_secure_session();
  require_same_origin();
  $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  $known = (string)($_SESSION['csrf_token'] ?? '');
  if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
    json_response(['ok' => false, 'error' => 'Sesija je istekla. Refrešujte stranicu i pokušajte ponovo.'], 403);
  }
}

function require_reseller(): array {
  start_secure_session();
  if (!isset($_SESSION['reseller_id'], $_SESSION['reseller_email'])) {
    json_response(['ok' => false, 'error' => 'Niste ulogovani. Refrešujte stranicu i ulogujte se ponovo.'], 401);
  }
  return [
    'id' => (int)$_SESSION['reseller_id'],
    'email' => (string)$_SESSION['reseller_email'],
  ];
}

function require_2fa_pending(): array {
  start_secure_session();
  $expires = (int)($_SESSION['pending_2fa_expires_at'] ?? 0);
  if (!isset($_SESSION['pending_reseller_id'], $_SESSION['pending_reseller_email']) || $expires < time()) {
    unset($_SESSION['pending_reseller_id'], $_SESSION['pending_reseller_email'], $_SESSION['pending_2fa_expires_at'], $_SESSION['pending_2fa_attempts']);
    json_response(['ok' => false, 'error' => '2-step sesija je istekla. Ulogujte se ponovo.'], 401);
  }
  return [
    'id' => (int)$_SESSION['pending_reseller_id'],
    'email' => (string)$_SESSION['pending_reseller_email'],
  ];
}

function require_admin_2fa_pending(): array {
  start_secure_session();
  $expires = (int)($_SESSION['pending_admin_2fa_expires_at'] ?? 0);
  if (!isset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username']) || $expires < time()) {
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    json_response(['ok' => false, 'error' => 'Admin 2-step sesija je istekla. Ulogujte se ponovo.'], 401);
  }
  return [
    'id' => (int)$_SESSION['pending_admin_id'],
    'username' => (string)$_SESSION['pending_admin_username'],
  ];
}

function require_admin(): array {
  start_secure_session();
  if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    json_response(['ok' => false, 'error' => 'Admin sesija je istekla. Refrešujte stranicu i ulogujte se ponovo.'], 401);
  }
  return [
    'id' => (int)$_SESSION['admin_id'],
    'username' => (string)$_SESSION['admin_username'],
  ];
}

function require_recent_admin_step_up(): void {
  if ((int)($_SESSION['admin_step_up_until'] ?? 0) < time()) {
    json_response(['ok' => false, 'error' => 'Potrebna je sveža owner potvrda pre ove akcije.'], 428);
  }
}

function h_string($value): string {
  return trim((string)$value);
}

function valid_email(string $email): bool {
  return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_internal_reseller_email(string $email): bool {
  $email = normalize_email($email);
  $suffix = '@playworld.rs';
  return $email !== '' && substr($email, -strlen($suffix)) === $suffix;
}

function table_columns(PDO $pdo, string $table): array {
  $stmt = $pdo->prepare("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ORDER BY ORDINAL_POSITION
  ");
  $stmt->execute([$table]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function column_names(PDO $pdo, string $table): array {
  return array_map(static fn($row) => $row['COLUMN_NAME'], table_columns($pdo, $table));
}

function has_column(PDO $pdo, string $table, string $column): bool {
  return in_array($column, column_names($pdo, $table), true);
}

function table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $stmt->execute([$table]);
  return (int)$stmt->fetchColumn() > 0;
}

function normalize_email(string $email): string {
  return strtolower(trim($email));
}

function normalize_phone(string $phone): string {
  $phone = trim($phone);
  $phone = preg_replace('/[^\d+]/', '', $phone) ?: '';
  if (strpos($phone, '00') === 0) {
    $phone = '+' . substr($phone, 2);
  }
  return $phone;
}

function valid_phone(string $phone): bool {
  $normalized = normalize_phone($phone);
  if ($normalized === '') return false;
  return preg_match('/^\+?[0-9]{7,15}$/', $normalized) === 1;
}

function client_ip(): string {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function user_agent(): string {
  return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function request_fingerprint(string $value): string {
  return hash('sha256', $value);
}

function safe_public_error(string $message): string {
  $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\-\/]+=*/i', 'Bearer [redacted]', $message) ?: '';
  $message = preg_replace('/pwrs_[A-Za-z0-9._~+\-\/]+/i', '[redacted-token]', $message) ?: '';
  return substr($message, 0, 500);
}

function ensure_security_tables(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  foreach ([
    'display_name VARCHAR(120) NULL',
    'phone VARCHAR(32) NULL',
    'profile_completed_at DATETIME NULL',
    'credential_changed_at DATETIME NULL',
    'security_2fa_reminded_at DATETIME NULL',
  ] as $definition) {
    $column = strtok($definition, ' ');
    if ($column && !has_column($pdo, 'resellers', $column)) {
      $pdo->exec("ALTER TABLE resellers ADD COLUMN {$definition}");
    }
  }

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS reseller_two_factor (
      reseller_id INT UNSIGNED NOT NULL,
      secret_encrypted TEXT NULL,
      pending_secret_encrypted TEXT NULL,
      enabled_at DATETIME NULL,
      disabled_at DATETIME NULL,
      last_used_at DATETIME NULL,
      recovery_codes_regenerated_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (reseller_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS reseller_recovery_codes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      reseller_id INT UNSIGNED NOT NULL,
      code_hash VARCHAR(255) NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_recovery_reseller (reseller_id, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_two_factor (
      admin_id INT UNSIGNED NOT NULL,
      secret_encrypted TEXT NULL,
      pending_secret_encrypted TEXT NULL,
      enabled_at DATETIME NULL,
      disabled_at DATETIME NULL,
      last_used_at DATETIME NULL,
      recovery_codes_regenerated_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (admin_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_recovery_codes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_id INT UNSIGNED NOT NULL,
      code_hash VARCHAR(255) NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_admin_recovery (admin_id, used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  foreach ([
    ['reseller_two_factor', 'last_totp_step BIGINT NULL'],
    ['admin_two_factor', 'last_totp_step BIGINT NULL'],
    ['owner_webauthn_challenges', 'options_json MEDIUMTEXT NOT NULL'],
  ] as [$table, $definition]) {
    $column = strtok($definition, ' ');
    if ($column && !has_column($pdo, $table, $column)) {
      $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
  }

  $done = true;
}

function ensure_order_cancellation_columns(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  foreach ([
    'canceled_at DATETIME NULL',
    'canceled_by_admin_id INT UNSIGNED NULL',
    'cancellation_reason VARCHAR(500) NULL',
  ] as $definition) {
    $column = strtok($definition, ' ');
    if ($column && !has_column($pdo, 'orders', $column)) {
      $pdo->exec("ALTER TABLE orders ADD COLUMN {$definition}");
    }
  }

  $done = true;
}

function ensure_order_observability_tables(PDO $pdo): void {
  static $done = false;
  if ($done) return;

  $pdo->exec(<<<'SQL'
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
  );

  $pdo->exec(<<<'SQL'
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
  );

  $done = true;
}

function order_reversal_transaction_type(PDO $pdo): string {
  $stmt = $pdo->prepare("SELECT DATA_TYPE, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_transactions' AND COLUMN_NAME = 'type' LIMIT 1");
  $stmt->execute();
  $column = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
  $definition = strtolower((string)($column['COLUMN_TYPE'] ?? ''));
  if ($type === 'enum' && strpos($definition, 'order_reversal') === false) {
    return 'ADMIN_ADJUSTMENT';
  }
  return 'ORDER_REVERSAL';
}

function audit_event(PDO $pdo, string $actorType, ?int $actorId, string $eventType, string $result, array $metadata = []): void {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare("
    INSERT INTO security_audit_events (actor_type, actor_id, event_type, result, ip_address, user_agent, metadata_json)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->execute([
    $actorType,
    $actorId,
    $eventType,
    $result,
    client_ip(),
    user_agent(),
    $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
  ]);
}

function reseller_profile(PDO $pdo, int $resellerId): ?array {
  $columns = column_names($pdo, 'resellers');
  $select = ['id', 'email', 'balance_rsd', 'status'];
  foreach (['display_name', 'phone', 'profile_completed_at', 'credential_changed_at', 'security_2fa_reminded_at'] as $column) {
    if (in_array($column, $columns, true)) $select[] = $column;
  }

  $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$resellerId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function security_encryption_key(): string {
  $configured = (string)config_value('security.encryption_key', getenv('SECURITY_ENCRYPTION_KEY') ?: getenv('APP_KEY') ?: '');
  if ($configured !== '') return hash('sha256', $configured, true);

  // Fallback keeps existing installs working, but production should set security.encryption_key.
  $fallback = (string)config_value('db.pass', '') . '|' . (string)config_value('admin.password_hash', '');
  return hash('sha256', $fallback !== '|' ? $fallback : __DIR__, true);
}

function encryption_available(): bool {
  if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) return false;
  $methods = array_map('strtolower', openssl_get_cipher_methods());
  return in_array('aes-256-gcm', $methods, true);
}

function encrypt_secret(string $plaintext): string {
  if (!encryption_available()) {
    throw new RuntimeException('Server nema podršku za AES-256-GCM enkripciju potrebnu za 2-step verifikaciju.');
  }
  $iv = random_bytes(12);
  $tag = '';
  $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', security_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
  if ($ciphertext === false) {
    throw new RuntimeException('Secret encryption failed.');
  }
  return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_secret(?string $encoded): string {
  if (!$encoded) return '';
  if (!encryption_available()) return '';
  $raw = base64_decode($encoded, true);
  if ($raw === false || strlen($raw) < 29) return '';
  $iv = substr($raw, 0, 12);
  $tag = substr($raw, 12, 16);
  $ciphertext = substr($raw, 28);
  $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', security_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
  return $plaintext === false ? '' : $plaintext;
}

function base32_encode_secret(string $bytes): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  for ($i = 0; $i < strlen($bytes); $i++) {
    $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
  }
  $out = '';
  for ($i = 0; $i < strlen($bits); $i += 5) {
    $chunk = substr($bits, $i, 5);
    if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
    $out .= $alphabet[bindec($chunk)];
  }
  return $out;
}

function base32_decode_secret(string $secret): string {
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?: '');
  $bits = '';
  for ($i = 0; $i < strlen($secret); $i++) {
    $pos = strpos($alphabet, $secret[$i]);
    if ($pos === false) continue;
    $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
  }
  $out = '';
  for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
    $out .= chr(bindec(substr($bits, $i, 8)));
  }
  return $out;
}

function generate_totp_secret(): string {
  return base32_encode_secret(random_bytes(20));
}

function hotp_code(string $secret, int $counter): string {
  $key = base32_decode_secret($secret);
  $binaryCounter = pack('N*', 0) . pack('N*', $counter);
  $hash = hash_hmac('sha1', $binaryCounter, $key, true);
  $offset = ord(substr($hash, -1)) & 0x0F;
  $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
  return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

function verify_totp_code(string $secret, string $code, int $window = 1): bool {
  $code = preg_replace('/\s+/', '', $code) ?: '';
  if (!preg_match('/^\d{6}$/', $code)) return false;
  $counter = (int)floor(time() / 30);
  for ($i = -$window; $i <= $window; $i++) {
    if (hash_equals(hotp_code($secret, $counter + $i), $code)) return true;
  }
  return false;
}

function matching_totp_counter(string $secret, string $code, int $window = 1): ?int {
  $code = preg_replace('/\s+/', '', $code) ?: '';
  if (!preg_match('/^\d{6}$/', $code)) return null;
  $counter = (int)floor(time() / 30);
  for ($i = -$window; $i <= $window; $i++) {
    $candidate = $counter + $i;
    if ($candidate >= 0 && hash_equals(hotp_code($secret, $candidate), $code)) return $candidate;
  }
  return null;
}

function verify_and_consume_totp(PDO $pdo, string $table, string $idColumn, int $id, string $secret, string $code): bool {
  $counter = matching_totp_counter($secret, $code);
  if ($counter === null) return false;
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("SELECT last_totp_step FROM {$table} WHERE {$idColumn} = ? FOR UPDATE");
    $stmt->execute([$id]);
    $last = $stmt->fetchColumn();
    if ($last !== false && $last !== null && (int)$last === $counter) {
      $pdo->rollBack();
      return false;
    }
    $update = $pdo->prepare("UPDATE {$table} SET last_totp_step = ?, updated_at = NOW() WHERE {$idColumn} = ?");
    $update->execute([$counter, $id]);
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function two_factor_row(PDO $pdo, int $resellerId): ?array {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare('SELECT * FROM reseller_two_factor WHERE reseller_id = ? LIMIT 1');
  $stmt->execute([$resellerId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function two_factor_enabled(PDO $pdo, int $resellerId): bool {
  $row = two_factor_row($pdo, $resellerId);
  return $row && (string)($row['enabled_at'] ?? '') !== '' && (string)($row['secret_encrypted'] ?? '') !== '';
}

function recovery_code_count(PDO $pdo, int $resellerId): int {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM reseller_recovery_codes WHERE reseller_id = ? AND used_at IS NULL');
  $stmt->execute([$resellerId]);
  return (int)$stmt->fetchColumn();
}

function generate_recovery_codes(PDO $pdo, int $resellerId): array {
  ensure_security_tables($pdo);
  $codes = [];
  $pdo->prepare('DELETE FROM reseller_recovery_codes WHERE reseller_id = ?')->execute([$resellerId]);
  $insert = $pdo->prepare('INSERT INTO reseller_recovery_codes (reseller_id, code_hash) VALUES (?, ?)');
  for ($i = 0; $i < 8; $i++) {
    $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
    $codes[] = $code;
    $insert->execute([$resellerId, password_hash($code, PASSWORD_DEFAULT)]);
  }
  $pdo->prepare('UPDATE reseller_two_factor SET recovery_codes_regenerated_at = NOW(), updated_at = NOW() WHERE reseller_id = ?')->execute([$resellerId]);
  return $codes;
}

function consume_recovery_code(PDO $pdo, int $resellerId, string $code): bool {
  ensure_security_tables($pdo);
  $code = strtoupper(trim($code));
  if ($code === '') return false;
  $stmt = $pdo->prepare('SELECT id, code_hash FROM reseller_recovery_codes WHERE reseller_id = ? AND used_at IS NULL ORDER BY id ASC');
  $stmt->execute([$resellerId]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (password_verify($code, (string)$row['code_hash'])) {
      $update = $pdo->prepare('UPDATE reseller_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
      $update->execute([(int)$row['id']]);
      return $update->rowCount() > 0;
    }
  }
  return false;
}

function admin_two_factor_row(PDO $pdo, int $adminId): ?array {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare('SELECT * FROM admin_two_factor WHERE admin_id = ? LIMIT 1');
  $stmt->execute([$adminId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function admin_two_factor_enabled(PDO $pdo, int $adminId): bool {
  $row = admin_two_factor_row($pdo, $adminId);
  return $row && (string)($row['enabled_at'] ?? '') !== '' && (string)($row['secret_encrypted'] ?? '') !== '';
}

function admin_recovery_code_count(PDO $pdo, int $adminId): int {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL');
  $stmt->execute([$adminId]);
  return (int)$stmt->fetchColumn();
}

function generate_admin_recovery_codes(PDO $pdo, int $adminId): array {
  ensure_security_tables($pdo);
  $codes = [];
  $pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
  $insert = $pdo->prepare('INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)');
  for ($i = 0; $i < 8; $i++) {
    $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
    $codes[] = $code;
    $insert->execute([$adminId, password_hash($code, PASSWORD_DEFAULT)]);
  }
  $pdo->prepare('UPDATE admin_two_factor SET recovery_codes_regenerated_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([$adminId]);
  return $codes;
}

function consume_admin_recovery_code(PDO $pdo, int $adminId, string $code): bool {
  ensure_security_tables($pdo);
  $code = strtoupper(trim($code));
  if ($code === '') return false;
  $stmt = $pdo->prepare('SELECT id, code_hash FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL ORDER BY id ASC');
  $stmt->execute([$adminId]);
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (password_verify($code, (string)$row['code_hash'])) {
      $update = $pdo->prepare('UPDATE admin_recovery_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
      $update->execute([(int)$row['id']]);
      return $update->rowCount() > 0;
    }
  }
  return false;
}

function verify_current_reseller_token(PDO $pdo, int $resellerId, string $token): bool {
  $stmt = $pdo->prepare('SELECT token_hash FROM resellers WHERE id = ? AND status = ? LIMIT 1');
  $stmt->execute([$resellerId, 'active']);
  $hash = (string)($stmt->fetchColumn() ?: '');
  return $hash !== '' && password_verify($token, $hash);
}

function verify_current_admin_password(PDO $pdo, int $adminId, string $password): bool {
  $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ? AND status = ? LIMIT 1');
  $stmt->execute([$adminId, 'active']);
  $hash = (string)($stmt->fetchColumn() ?: '');
  return $hash !== '' && password_verify($password, $hash);
}

function otpauth_uri(string $issuer, string $account, string $secret): string {
  $label = rawurlencode($issuer . ':' . $account);
  return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function profile_is_complete(PDO $pdo, int $resellerId): bool {
  if (!has_column($pdo, 'resellers', 'profile_completed_at')) {
    return true;
  }
  $stmt = $pdo->prepare('SELECT email, phone, profile_completed_at FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$resellerId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return false;
  return (string)($row['profile_completed_at'] ?? '') !== ''
    && valid_email((string)($row['email'] ?? ''))
    && !is_internal_reseller_email((string)($row['email'] ?? ''))
    && (!has_column($pdo, 'resellers', 'phone') || valid_phone((string)($row['phone'] ?? '')));
}

function require_completed_profile(PDO $pdo, int $resellerId): void {
  if (!profile_is_complete($pdo, $resellerId)) {
    json_response([
      'ok' => false,
      'error' => 'Dovršite podešavanja naloga pre nastavka korišćenja panela.',
      'profile_required' => true,
    ], 428);
  }
}

function enforce_rate_limit(PDO $pdo, string $actorType, string $identifier, int $maxAttempts, int $windowSeconds, string $errorMessage): void {
  ensure_security_tables($pdo);
  $hash = request_fingerprint($identifier);
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM login_attempts
    WHERE actor_type = ?
      AND identifier_hash = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
  ");
  $stmt->execute([$actorType, $hash, $windowSeconds]);
  if ((int)$stmt->fetchColumn() >= $maxAttempts) {
    json_response(['ok' => false, 'error' => $errorMessage], 429);
  }
}

function record_login_attempt(PDO $pdo, string $actorType, string $identifier, bool $success): void {
  ensure_security_tables($pdo);
  $stmt = $pdo->prepare("
    INSERT INTO login_attempts (actor_type, identifier_hash, ip_address, success)
    VALUES (?, ?, ?, ?)
  ");
  $stmt->execute([$actorType, request_fingerprint($identifier), client_ip(), $success ? 1 : 0]);
}

function app_base_url(): string {
  return rtrim((string)config_value('inventory.api_base', getenv('PWRS_INVENTORY_API_BASE') ?: 'https://baza.igreps.rs'), '/');
}

function inventory_supplier_token(): string {
  return (string)config_value('inventory.supplier_token', getenv('PWRS_INVENTORY_SUPPLIER_TOKEN') ?: '');
}

function mask_email(string $email): string {
  $email = normalize_email($email);
  if (!valid_email($email)) return '';
  [$local, $domain] = explode('@', $email, 2);
  $first = substr($local, 0, 1);
  return $first . str_repeat('*', max(3, strlen($local) - 1)) . '@' . $domain;
}

function mask_secret(string $secret): string {
  $secret = trim($secret);
  if ($secret === '') return '';
  if (strlen($secret) <= 8) return str_repeat('*', strlen($secret));
  return substr($secret, 0, 4) . str_repeat('*', max(8, strlen($secret) - 8)) . substr($secret, -4);
}

function app_today(): string {
  $timezone = (string)config_value('app.timezone', 'Europe/Belgrade');
  try {
    return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
  } catch (Throwable $e) {
    return date('Y-m-d');
  }
}

function notification_recipients(string $path, array $defaults = []): array {
  $configured = config_value($path, '');
  $values = is_array($configured)
    ? $configured
    : preg_split('/[\s,;]+/', trim((string)$configured), -1, PREG_SPLIT_NO_EMPTY);
  $recipients = [];

  foreach (array_merge($defaults, $values ?: []) as $value) {
    $email = normalize_email((string)$value);
    if ($email !== '' && valid_email($email) && !in_array($email, $recipients, true)) {
      $recipients[] = $email;
    }
  }
  return $recipients;
}

function notification_from_address(): string {
  $from = normalize_email((string)config_value('mail.from', 'no-reply@reseller.psigre.rs'));
  return valid_email($from) ? $from : 'no-reply@reseller.psigre.rs';
}

function order_notification(array $order): array {
  $productName = h_string($order['product_name'] ?? $order['product_id'] ?? 'Proizvod');
  $accountType = h_string($order['account_type'] ?? '');
  $price = (int)($order['price_rsd'] ?? 0);
  $resellerEmail = normalize_email((string)($order['reseller_email'] ?? ''));
  $customerEmail = normalize_email((string)($order['buyer_email'] ?? $order['customer_email'] ?? $resellerEmail));
  $resellerName = h_string($order['reseller_name'] ?? $order['display_name'] ?? '');
  $resellerPhone = h_string($order['reseller_phone'] ?? $order['phone'] ?? '');

  $message = "Nova reseller porudzbina\n";
  $message .= "========================\n\n";
  $message .= "Proizvod: " . $productName . "\n";
  $message .= "Tip naloga: " . $accountType . "\n";
  $message .= "Cena: " . $price . " RSD\n\n";
  $message .= "Reseller\n";
  if ($resellerName !== '') $message .= "Ime: " . $resellerName . "\n";
  $message .= "Email: " . $resellerEmail . "\n";
  if ($resellerPhone !== '') $message .= "Telefon: " . $resellerPhone . "\n";
  if (!empty($order['reseller_id'])) $message .= "ID: " . (int)$order['reseller_id'] . "\n";
  $message .= "\nIsporuka\n";
  $message .= "Email resellera: " . $customerEmail . "\n\n";
  $message .= "Vreme: " . gmdate('Y-m-d H:i:s') . " UTC\n";

  return [
    'subject' => 'Nova reseller porudzbina - ' . $productName,
    'message' => $message,
    'recipients' => notification_recipients('mail.order_to', [
      'arsenijee19@gmail.com',
      'support@licenca.rs',
    ]),
  ];
}

function send_text_notification_email(array $recipients, string $subject, string $message): array {
  $subject = trim((string)(preg_replace('/[\r\n]+/', ' ', $subject) ?: 'PlayWorld obaveštenje'));
  $headers = "From: " . notification_from_address() . "\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
  $headers .= "X-Mailer: PlayWorld Reseller Portal\r\n";
  $sent = [];
  $failed = [];

  foreach ($recipients as $recipient) {
    if (@mail($recipient, $subject, $message, $headers)) {
      $sent[] = $recipient;
    } else {
      $failed[] = $recipient;
    }
  }

  return [
    'ok' => count($recipients) > 0 && count($failed) === 0,
    'recipients' => $recipients,
    'sent' => $sent,
    'failed' => $failed,
    'error' => $failed
      ? 'Mail server nije prihvatio: ' . implode(', ', $failed)
      : ($recipients ? '' : 'Nema podešenih primalaca.'),
  ];
}

function record_order_delivery_event(PDO $pdo, int $orderId, string $eventType, string $status, array $details = []): void {
  if ($orderId <= 0 || !table_exists($pdo, 'order_delivery_events')) return;

  $recipients = is_array($details['recipients'] ?? null)
    ? implode(', ', array_map('strval', $details['recipients']))
    : h_string($details['recipients'] ?? '');
  $httpStatus = isset($details['http_status']) ? (int)$details['http_status'] : null;
  $error = safe_public_error(h_string($details['error'] ?? ''));
  $payload = isset($details['payload']) ? json_encode($details['payload'], JSON_UNESCAPED_UNICODE) : null;
  $attempts = max(1, (int)($details['attempts'] ?? 1));

  $existing = $pdo->prepare('SELECT id FROM order_delivery_events WHERE order_id = ? AND event_type = ? LIMIT 1');
  $existing->execute([$orderId, $eventType]);
  $eventId = $existing->fetchColumn();
  if ($eventId) {
    $stmt = $pdo->prepare('UPDATE order_delivery_events SET status = ?, recipients = ?, http_status = ?, attempts = attempts + ?, error_message = ?, payload_json = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $recipients ?: null, $httpStatus, $attempts, $error ?: null, $payload, (int)$eventId]);
    return;
  }

  $stmt = $pdo->prepare('INSERT INTO order_delivery_events (order_id, event_type, status, recipients, http_status, attempts, error_message, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->execute([$orderId, $eventType, $status, $recipients ?: null, $httpStatus, $attempts, $error ?: null, $payload]);
}

function post_json(string $url, array $payload, int $timeoutSeconds = 12, array $extraHeaders = []): array {
  $parts = parse_url($url);
  $host = strtolower((string)($parts['host'] ?? ''));
  if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user'], $parts['pass']) || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
    throw new RuntimeException('Outbound webhook mora koristiti HTTPS i validan javni host.');
  }
  if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    throw new RuntimeException('Outbound webhook ne može koristiti privatnu IP adresu.');
  }
  $resolvedIps = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
  if (!$resolvedIps) throw new RuntimeException('Outbound webhook host nije moguće razrešiti.');
  foreach ($resolvedIps as $resolvedIp) {
    if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
      throw new RuntimeException('Outbound webhook host razrešava se na privatnu IP adresu.');
    }
  }
  $ch = curl_init($url);
  $headers = array_merge(["Content-Type: application/json"], $extraHeaders);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
  ]);
  $start = microtime(true);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return [
    "ok" => ($err === "" && $code >= 200 && $code < 300),
    "code" => $code,
    "err" => $err,
    "body" => $body,
    "duration_ms" => (int)round((microtime(true) - $start) * 1000),
  ];
}
