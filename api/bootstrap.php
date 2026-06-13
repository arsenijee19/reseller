<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Lax',
    'path' => '/',
  ]);
  session_start();
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
    json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
  }
}

function require_reseller(): array {
  start_secure_session();
  if (!isset($_SESSION['reseller_id'], $_SESSION['reseller_email'])) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
  return [
    'id' => (int)$_SESSION['reseller_id'],
    'email' => (string)$_SESSION['reseller_email'],
  ];
}

function require_admin(): array {
  start_secure_session();
  if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
  }
  return [
    'id' => (int)$_SESSION['admin_id'],
    'username' => (string)$_SESSION['admin_username'],
  ];
}

function h_string(mixed $value): string {
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
