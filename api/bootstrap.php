<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SESSION_LIFETIME_SECONDS = 3600;

function json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json_body(): array {
  $input = json_decode(file_get_contents('php://input'), true);
  return is_array($input) ? $input : [];
}

function start_secure_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;

  ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME_SECONDS);

  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME_SECONDS,
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Lax',
    'path' => '/',
  ]);
  session_start();

  if (ini_get('session.use_cookies') && session_id() !== '') {
    setcookie(session_name(), session_id(), [
      'expires' => time() + SESSION_LIFETIME_SECONDS,
      'path' => '/',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }

  $now = time();
  $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
  if ($lastActivity > 0 && ($now - $lastActivity) > SESSION_LIFETIME_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
  }
  $_SESSION['last_activity'] = $now;
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

function h_string($value): string {
  return trim((string)$value);
}

function valid_email(string $email): bool {
  return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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

  $done = true;
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
  foreach (['phone', 'profile_completed_at', 'credential_changed_at', 'security_2fa_reminded_at'] as $column) {
    if (in_array($column, $columns, true)) $select[] = $column;
  }

  $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$resellerId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function profile_is_complete(PDO $pdo, int $resellerId): bool {
  if (!has_column($pdo, 'resellers', 'profile_completed_at')) {
    return true;
  }
  $stmt = $pdo->prepare('SELECT profile_completed_at FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$resellerId]);
  return (string)($stmt->fetchColumn() ?: '') !== '';
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

function post_json(string $url, array $payload, int $timeoutSeconds = 12, array $extraHeaders = []): array {
  $ch = curl_init($url);
  $headers = array_merge(["Content-Type: application/json"], $extraHeaders);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
    CURLOPT_TIMEOUT => $timeoutSeconds,
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
