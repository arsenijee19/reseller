<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session('admin');

$action = h_string($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
  require_same_origin();
  require_json_content_type();
}

function require_admin_passkey_step_up(PDO $pdo, array $admin, array $input): void {
  $adminId = (int)$admin['id'];
  if (!verify_current_admin_password($pdo, $adminId, (string)($input['current_password'] ?? ''))) {
    audit_event($pdo, 'admin', $adminId, 'admin_passkey_step_up_failed', 'failed', ['reason' => 'bad_password']);
    json_response(['ok' => false, 'error' => 'Trenutna admin šifra nije tačna.'], 401);
  }
  if (admin_two_factor_enabled($pdo, $adminId)) {
    $row = admin_two_factor_row($pdo, $adminId);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    $valid = $secret !== '' && verify_and_consume_totp($pdo, 'admin_two_factor', 'admin_id', $adminId, $secret, (string)($input['two_factor_code'] ?? ''));
    if (!$valid) {
      audit_event($pdo, 'admin', $adminId, 'admin_passkey_step_up_failed', 'failed', ['reason' => 'bad_2fa']);
      json_response(['ok' => false, 'error' => 'Za ovu bezbednosnu akciju potreban je važeći 2-step kod.'], 401);
    }
  }
}

function require_webauthn_runtime(): void {
  if (PHP_VERSION_ID < 80401) {
    json_response(['ok' => false, 'error' => 'Passkey prijava zahteva PHP 8.4.1 ili noviji. Password + 2-step prijava je i dalje dostupna.'], 503);
  }
  require_once __DIR__ . '/webauthn.php';
}

function fetch_resellers(PDO $pdo): array {
  ensure_security_tables($pdo);
  $columns = column_names($pdo, 'resellers');
  $select = ['id', 'email', 'status', 'balance_rsd'];
  foreach (['display_name', 'phone', 'profile_completed_at', 'credential_changed_at', 'security_2fa_reminded_at'] as $column) {
    if (in_array($column, $columns, true)) $select[] = $column;
  }
  $select[] = "(SELECT enabled_at FROM reseller_two_factor tf WHERE tf.reseller_id = resellers.id LIMIT 1) AS two_factor_enabled_at";
  $select[] = "(SELECT last_used_at FROM reseller_two_factor tf WHERE tf.reseller_id = resellers.id LIMIT 1) AS two_factor_last_used_at";
  $select[] = "(SELECT COUNT(*) FROM reseller_recovery_codes rc WHERE rc.reseller_id = resellers.id AND rc.used_at IS NULL) AS recovery_codes_remaining";
  $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM resellers ORDER BY id DESC');
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_products(PDO $pdo): array {
  $stmt = $pdo->query('SELECT * FROM product_prices ORDER BY product_name ASC, account_type ASC, product_id ASC');
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_orders(PDO $pdo, array $filters): array {
  try {
    ensure_order_observability_tables($pdo);
  } catch (Throwable $ignored) {
    // Legacy DB users may not have CREATE privileges; the core order view must still load.
  }
  $hasDeliveryEvents = false;
  try {
    $hasDeliveryEvents = table_exists($pdo, 'order_delivery_events');
  } catch (Throwable $ignored) {}
  $where = [];
  $params = [];

  if (h_string($filters['reseller_id'] ?? '') !== '') {
    $where[] = 'o.reseller_id = ?';
    $params[] = (int)$filters['reseller_id'];
  }
  if (h_string($filters['status'] ?? '') !== '') {
    $where[] = 'o.status = ?';
    $params[] = h_string($filters['status']);
  }
  if (h_string($filters['product_id'] ?? '') !== '') {
    $where[] = 'o.product_id = ?';
    $params[] = h_string($filters['product_id']);
  }
  if (h_string($filters['date_from'] ?? '') !== '') {
    $where[] = 'DATE(o.created_at) >= ?';
    $params[] = h_string($filters['date_from']);
  }
  if (h_string($filters['date_to'] ?? '') !== '') {
    $where[] = 'DATE(o.created_at) <= ?';
    $params[] = h_string($filters['date_to']);
  }

  $notificationSelect = $hasDeliveryEvents
    ? "oe.email_status AS notification_email_status,
      oe.email_attempts AS notification_email_attempts,
      oe.email_error AS notification_email_error,
      oe.n8n_status AS notification_n8n_status,
      oe.n8n_attempts AS notification_n8n_attempts,
      oe.n8n_http_status AS notification_n8n_http_status,
      oe.n8n_error AS notification_n8n_error"
    : "NULL AS notification_email_status,
      NULL AS notification_email_attempts,
      NULL AS notification_email_error,
      NULL AS notification_n8n_status,
      NULL AS notification_n8n_attempts,
      NULL AS notification_n8n_http_status,
      NULL AS notification_n8n_error";

  $sql = "
    SELECT o.*, pp.product_name, pp.account_type,
      {$notificationSelect}
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
  ";
  if ($hasDeliveryEvents) {
    $sql .= "
      LEFT JOIN (
        SELECT
          order_id,
          MAX(CASE WHEN event_type = 'email' THEN status END) AS email_status,
          MAX(CASE WHEN event_type = 'email' THEN attempts END) AS email_attempts,
          MAX(CASE WHEN event_type = 'email' THEN error_message END) AS email_error,
          MAX(CASE WHEN event_type = 'n8n' THEN status END) AS n8n_status,
          MAX(CASE WHEN event_type = 'n8n' THEN attempts END) AS n8n_attempts,
          MAX(CASE WHEN event_type = 'n8n' THEN http_status END) AS n8n_http_status,
          MAX(CASE WHEN event_type = 'n8n' THEN error_message END) AS n8n_error
        FROM order_delivery_events
        GROUP BY order_id
      ) oe ON oe.order_id = o.id
    ";
  }
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT 250';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_payment_notices(PDO $pdo): array {
  try {
    ensure_order_observability_tables($pdo);
  } catch (Throwable $ignored) {}
  try {
    if (!table_exists($pdo, 'payment_notice_requests')) return [];
  } catch (Throwable $ignored) {
    return [];
  }
  $stmt = $pdo->query("
    SELECT p.*, r.display_name AS reseller_name, r.email AS current_reseller_email
    FROM payment_notice_requests p
    LEFT JOIN resellers r ON r.id = p.reseller_id
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 250
  ");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_inventory_requests(PDO $pdo, array $filters): array {
  ensure_security_tables($pdo);
  $where = [];
  $params = [];

  if (h_string($filters['inventory_reseller_id'] ?? '') !== '') {
    $where[] = 'i.reseller_id = ?';
    $params[] = (int)$filters['inventory_reseller_id'];
  }
  if (h_string($filters['inventory_account_email'] ?? '') !== '') {
    $where[] = 'i.account_email LIKE ?';
    $params[] = '%' . h_string($filters['inventory_account_email']) . '%';
  }
  if (h_string($filters['inventory_result'] ?? '') !== '') {
    $where[] = 'i.result = ?';
    $params[] = h_string($filters['inventory_result']);
  }
  if (h_string($filters['inventory_http_status'] ?? '') !== '') {
    $where[] = 'i.http_status = ?';
    $params[] = (int)$filters['inventory_http_status'];
  }
  if (h_string($filters['inventory_from'] ?? '') !== '') {
    $where[] = 'DATE(i.created_at) >= ?';
    $params[] = h_string($filters['inventory_from']);
  }
  if (h_string($filters['inventory_to'] ?? '') !== '') {
    $where[] = 'DATE(i.created_at) <= ?';
    $params[] = h_string($filters['inventory_to']);
  }

  $sql = "
    SELECT i.*, r.email AS reseller_email
    FROM inventory_api_requests i
    LEFT JOIN resellers r ON r.id = i.reseller_id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY i.created_at DESC, i.id DESC LIMIT 250';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_missing_game_reports(PDO $pdo): array {
  ensure_security_tables($pdo);
  $stmt = $pdo->query("
    SELECT m.*, r.email AS reseller_email, o.product_id, o.created_at AS order_created_at, pp.product_name, pp.account_type
    FROM missing_game_reports m
    LEFT JOIN resellers r ON r.id = m.reseller_id
    LEFT JOIN orders o ON o.id = m.order_id
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT 100
  ");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_security_audit_events(PDO $pdo): array {
  ensure_security_tables($pdo);
  $stmt = $pdo->query("
    SELECT id, actor_type, actor_id, event_type, result, ip_address, created_at
    FROM security_audit_events
    ORDER BY created_at DESC, id DESC
    LIMIT 100
  ");
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inventory_config_payload(PDO $pdo): array {
  ensure_security_tables($pdo);
  $base = app_base_url();
  $token = inventory_supplier_token();
  $lastSuccess = $pdo->query("
    SELECT response_received_at
    FROM inventory_api_requests
    WHERE result IN ('success', 'duplicate')
    ORDER BY response_received_at DESC
    LIMIT 1
  ")->fetchColumn();
  $lastError = $pdo->query("
    SELECT result, http_status, error_message, response_received_at, created_at
    FROM inventory_api_requests
    WHERE result NOT IN ('success', 'duplicate')
    ORDER BY COALESCE(response_received_at, created_at) DESC
    LIMIT 1
  ")->fetch(PDO::FETCH_ASSOC);
  $lastCommunication = $pdo->query("
    SELECT COALESCE(response_received_at, created_at)
    FROM inventory_api_requests
    ORDER BY COALESCE(response_received_at, created_at) DESC
    LIMIT 1
  ")->fetchColumn();

  return [
    'api_base_url' => $base,
    'token_configured' => $token !== '',
    'token_masked' => $token !== '' ? mask_secret($token) : '',
    'token_source' => 'server_config',
    'health_test_available' => false,
    'last_success_at' => $lastSuccess ?: '',
    'last_error' => $lastError ?: null,
    'last_communication_at' => $lastCommunication ?: '',
  ];
}

function clean_admin_value($value) {
  if (!is_string($value)) return $value;
  $value = trim($value);
  if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
    return substr($value, 1, -1);
  }
  return $value;
}

function ensure_admin_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS admin_users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      username VARCHAR(100) NOT NULL,
      password_hash VARCHAR(255) NOT NULL,
      status ENUM('active','inactive') NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_admin_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

function seed_admin_if_needed(PDO $pdo): void {
  ensure_admin_table($pdo);

  $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
  if ($count > 0) return;

  $username = h_string(config_value('admin.username', 'admin'));
  $hash = h_string(config_value('admin.password_hash', ''));

  if ($username === '' || $hash === '') {
    throw new RuntimeException('Initial admin configuration is missing.');
  }

  $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash, status) VALUES (?, ?, ?)');
  $stmt->execute([$username, $hash, 'active']);
}

function admin_twofa_public_status(PDO $pdo, int $adminId): array {
  $row = admin_two_factor_row($pdo, $adminId);
  return [
    'enabled' => admin_two_factor_enabled($pdo, $adminId),
    'enabled_at' => (string)($row['enabled_at'] ?? ''),
    'last_used_at' => (string)($row['last_used_at'] ?? ''),
    'recovery_codes_regenerated_at' => (string)($row['recovery_codes_regenerated_at'] ?? ''),
    'recovery_codes_remaining' => admin_recovery_code_count($pdo, $adminId),
  ];
}

function temporary_reseller_email(string $displayName): string {
  $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', $displayName) ?: '');
  if ($base === '') $base = 'reseller';
  $base = substr($base, 0, 32);
  return $base . '-' . date('YmdHis') . '-' . random_int(100, 999) . '@playworld.rs';
}

function dashboard_payload(PDO $pdo, array $filters = []): array {
  ensure_security_tables($pdo);
  try {
    ensure_order_observability_tables($pdo);
  } catch (Throwable $ignored) {
    // Keep legacy installations usable until the explicit reliability migration is run.
  }
  $orderStatuses = [];
  if (has_column($pdo, 'orders', 'status')) {
    $orderStatuses = $pdo->query("SELECT DISTINCT status FROM orders WHERE status IS NOT NULL AND status <> '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
  }

  return [
    'ok' => true,
    'csrf_token' => csrf_token(),
    'schema' => [
      'resellers' => table_columns($pdo, 'resellers'),
      'products' => table_columns($pdo, 'product_prices'),
      'orders' => table_columns($pdo, 'orders'),
      'inventory_api_requests' => table_columns($pdo, 'inventory_api_requests'),
      'missing_game_reports' => table_columns($pdo, 'missing_game_reports'),
      'security_audit_events' => table_columns($pdo, 'security_audit_events'),
      'order_delivery_events' => table_columns($pdo, 'order_delivery_events'),
      'payment_notice_requests' => table_columns($pdo, 'payment_notice_requests'),
    ],
    'resellers' => fetch_resellers($pdo),
    'products' => fetch_products($pdo),
    'orders' => fetch_orders($pdo, $filters),
    'payment_notices' => fetch_payment_notices($pdo),
    'inventory_config' => inventory_config_payload($pdo),
    'inventory_requests' => fetch_inventory_requests($pdo, $filters),
    'missing_game_reports' => fetch_missing_game_reports($pdo),
    'security_audit_events' => fetch_security_audit_events($pdo),
    'order_statuses' => $orderStatuses,
  ];
}

try {
  $pdo = db();
  ensure_admin_table($pdo);
  ensure_security_tables($pdo);

  if ($action === 'session') {
    json_response([
      'ok' => true,
      'logged_in' => isset($_SESSION['admin_id']),
      'username' => h_string($_SESSION['admin_username'] ?? ''),
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($action === 'login') {
    require_post();
    $input = read_json_body();
    $password = (string)($input['password'] ?? '');

    if ($password === '') {
      json_response(['ok' => false, 'error' => 'Unesi admin šifru.'], 400);
    }

    seed_admin_if_needed($pdo);
    enforce_rate_limit(
      $pdo,
      'admin_login',
      client_ip(),
      10,
      900,
      'Previše pokušaja prijave. Sačekajte nekoliko minuta i pokušajte ponovo.'
    );

    $username = h_string(config_value('admin.username', 'admin'));
    $stmt = $pdo->prepare('SELECT id, username, password_hash, status FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $admin['status'] !== 'active' || !password_verify($password, (string)$admin['password_hash'])) {
      record_login_attempt($pdo, 'admin_login', client_ip(), false);
      audit_event($pdo, 'admin', null, 'admin_login_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Pogrešna admin šifra.'], 401);
    }

    record_login_attempt($pdo, 'admin_login', client_ip(), true);
    if (admin_two_factor_enabled($pdo, (int)$admin['id'])) {
      session_regenerate_id(true);
      $_SESSION = [];
      $_SESSION['pending_admin_id'] = (int)$admin['id'];
      $_SESSION['pending_admin_username'] = (string)$admin['username'];
      $_SESSION['pending_admin_2fa_expires_at'] = time() + 300;
      $_SESSION['pending_admin_2fa_attempts'] = 0;
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_login_first_factor_success', 'success');
      json_response(['ok' => true, 'requires_2fa' => true, 'csrf_token' => csrf_token()]);
    }

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
    $_SESSION['admin_step_up_until'] = time() + 900;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_login_success', 'success');

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  if ($action === '2fa_challenge') {
    require_post();
    require_csrf();
    $pending = require_admin_2fa_pending();
    $input = read_json_body();
    $code = trim((string)($input['code'] ?? ''));

    enforce_rate_limit($pdo, 'admin_2fa', 'admin:' . $pending['id'], 8, 900, 'Previše pokušaja. Sačekajte nekoliko minuta i pokušajte ponovo.');
    $_SESSION['pending_admin_2fa_attempts'] = (int)($_SESSION['pending_admin_2fa_attempts'] ?? 0) + 1;
    if ((int)$_SESSION['pending_admin_2fa_attempts'] > 8) {
      record_login_attempt($pdo, 'admin_2fa', 'admin:' . $pending['id'], false);
      json_response(['ok' => false, 'error' => 'Previše pokušaja. Ulogujte se ponovo.'], 429);
    }

    $row = admin_two_factor_row($pdo, (int)$pending['id']);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    $ok = $secret !== '' && verify_and_consume_totp($pdo, 'admin_two_factor', 'admin_id', (int)$pending['id'], $secret, $code);
    $usedRecovery = false;
    if (!$ok) {
      $ok = consume_admin_recovery_code($pdo, (int)$pending['id'], $code);
      $usedRecovery = $ok;
    }

    record_login_attempt($pdo, 'admin_2fa', 'admin:' . $pending['id'], $ok);
    if (!$ok) {
      audit_event($pdo, 'admin', (int)$pending['id'], 'admin_two_factor_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Kod nije ispravan ili je istekao. Pokušajte ponovo.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['admin_id'] = (int)$pending['id'];
    $_SESSION['admin_username'] = (string)$pending['username'];
    $_SESSION['admin_step_up_until'] = time() + 900;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    $pdo->prepare('UPDATE admin_two_factor SET last_used_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([(int)$pending['id']]);
    audit_event($pdo, 'admin', (int)$pending['id'], $usedRecovery ? 'admin_two_factor_recovery_login' : 'admin_two_factor_success', 'success');

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  if ($action === '2fa_cancel') {
    require_post();
    require_csrf();
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_step_up_until'], $_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  if ($action === 'passkey_login_options') {
    require_webauthn_runtime();
    require_post();
    enforce_rate_limit($pdo, 'admin_passkey_options', client_ip(), 20, 900, 'Previše pokušaja. Sačekajte nekoliko minuta i pokušajte ponovo.');
    $challenge = webauthn_new_challenge();
    $options = webauthn_request_options($challenge);
    webauthn_store_challenge($pdo, 'login', $challenge, $options);
    json_response(['ok' => true, 'public_key' => webauthn_json_options($options)]);
  }

  if ($action === 'passkey_login_verify') {
    require_webauthn_runtime();
    require_post();
    $input = read_json_body();
    $responseJson = (string)($input['credential_json'] ?? '');
    if ($responseJson === '' || strlen($responseJson) > MAX_JSON_BODY_BYTES) {
      json_response(['ok' => false, 'error' => 'Passkey prijava nije validna.'], 400);
    }
    enforce_rate_limit($pdo, 'admin_passkey_login', client_ip(), 10, 900, 'Previše pokušaja prijave. Sačekajte nekoliko minuta i pokušajte ponovo.');
    $challengeKey = webauthn_response_challenge_key($responseJson);
    $challenge = webauthn_take_challenge($pdo, 'login', $challengeKey);
    if (!$challenge) json_response(['ok' => false, 'error' => 'Passkey zahtev je istekao. Pokušajte ponovo.'], 401);

    $decoded = json_decode($responseJson, true, 32, JSON_THROW_ON_ERROR);
    $credentialId = trim((string)($decoded['id'] ?? ''));
    $stmt = $pdo->prepare('SELECT * FROM owner_passkeys WHERE credential_id = ? AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([$credentialId]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stored) {
      record_login_attempt($pdo, 'admin_passkey_login', client_ip(), false);
      json_response(['ok' => false, 'error' => 'Passkey prijava nije uspela.'], 401);
    }
    $record = webauthn_load_credential_record((string)$stored['credential_record_json']);
    $options = webauthn_serializer()->deserialize((string)$challenge['options_json'], Webauthn\PublicKeyCredentialRequestOptions::class, 'json');
    if (!$options instanceof Webauthn\PublicKeyCredentialRequestOptions) throw new RuntimeException('Passkey opcije nisu validne.');
    $record = webauthn_validate_assertion($responseJson, $record, $options);
    $pdo->prepare('UPDATE owner_passkeys SET credential_record_json = ?, sign_count = ?, last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL')
      ->execute([webauthn_serializer()->serialize($record, 'json'), $record->counter, (int)$stored['id']]);
    $adminStmt = $pdo->prepare("SELECT id, username FROM admin_users WHERE id = ? AND status = 'active' LIMIT 1");
    $adminStmt->execute([(int)$stored['admin_id']]);
    $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) json_response(['ok' => false, 'error' => 'Passkey prijava nije uspela.'], 401);
    record_login_attempt($pdo, 'admin_passkey_login', client_ip(), true);
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
    $_SESSION['admin_step_up_until'] = time() + 900;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_passkey_login_success', 'success', ['passkey_id' => (int)$stored['id']]);
    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  require_admin();
  ensure_order_cancellation_columns($pdo);

  if ($action === 'logout') {
    require_post();
    require_csrf();
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_step_up_until'], $_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    json_response(['ok' => true]);
  }

  if ($action === 'dashboard') {
    json_response(dashboard_payload($pdo, $_GET));
  }

  if ($action === '2fa_status') {
    $admin = require_admin();
    json_response(['ok' => true, 'two_factor' => admin_twofa_public_status($pdo, (int)$admin['id']), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'passkey_status') {
    $admin = require_admin();
    $stmt = $pdo->prepare('SELECT id, label, created_at, last_used_at FROM owner_passkeys WHERE admin_id = ? AND revoked_at IS NULL ORDER BY created_at ASC, id ASC');
    $stmt->execute([(int)$admin['id']]);
    json_response(['ok' => true, 'passkeys' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'admin_reauth') {
    $admin = require_admin();
    require_post();
    require_csrf();
    $input = read_json_body();
    require_admin_passkey_step_up($pdo, $admin, $input);
    $_SESSION['admin_step_up_until'] = time() + 900;
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_step_up_success', 'success');
    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  require_post();
  require_csrf();
  $input = read_json_body();

  if ($action === '2fa_setup_start') {
    $admin = require_admin();
    $currentPassword = (string)($input['current_password'] ?? '');
    if (!verify_current_admin_password($pdo, (int)$admin['id'], $currentPassword)) {
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_setup_start_failed', 'failed', ['reason' => 'bad_current_password']);
      json_response(['ok' => false, 'error' => 'Trenutna admin šifra nije tačna.'], 401);
    }
    $secret = generate_totp_secret();
    $encrypted = encrypt_secret($secret);
    $stmt = $pdo->prepare("
      INSERT INTO admin_two_factor (admin_id, pending_secret_encrypted)
      VALUES (?, ?)
      ON DUPLICATE KEY UPDATE pending_secret_encrypted = VALUES(pending_secret_encrypted), updated_at = NOW()
    ");
    $stmt->execute([(int)$admin['id'], $encrypted]);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_setup_started', 'success');
    json_response([
      'ok' => true,
      'manual_key' => $secret,
      'otpauth_uri' => otpauth_uri('PlayWorld Admin', (string)$admin['username'], $secret),
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($action === '2fa_setup_confirm') {
    $admin = require_admin();
    $code = (string)($input['code'] ?? '');
    $row = admin_two_factor_row($pdo, (int)$admin['id']);
    $secret = decrypt_secret((string)($row['pending_secret_encrypted'] ?? ''));
    if ($secret === '' || !verify_totp_code($secret, $code)) {
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_setup_confirm_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Kod nije ispravan ili je istekao.'], 401);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
      UPDATE admin_two_factor
      SET secret_encrypted = pending_secret_encrypted,
          pending_secret_encrypted = NULL,
          enabled_at = COALESCE(enabled_at, NOW()),
          disabled_at = NULL,
          last_used_at = NOW(),
          updated_at = NOW()
      WHERE admin_id = ?
    ");
    $stmt->execute([(int)$admin['id']]);
    $codes = generate_admin_recovery_codes($pdo, (int)$admin['id']);
    $pdo->commit();
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_enabled', 'success');
    json_response(['ok' => true, 'recovery_codes' => $codes, 'two_factor' => admin_twofa_public_status($pdo, (int)$admin['id']), 'csrf_token' => csrf_token()]);
  }

  if ($action === '2fa_regenerate_recovery') {
    $admin = require_admin();
    $currentPassword = (string)($input['current_password'] ?? '');
    $code = (string)($input['code'] ?? '');
    $row = admin_two_factor_row($pdo, (int)$admin['id']);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    if (!verify_current_admin_password($pdo, (int)$admin['id'], $currentPassword) || $secret === '' || !verify_and_consume_totp($pdo, 'admin_two_factor', 'admin_id', (int)$admin['id'], $secret, $code)) {
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_recovery_codes_regenerate_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Potvrda nije ispravna.'], 401);
    }
    $codes = generate_admin_recovery_codes($pdo, (int)$admin['id']);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_recovery_codes_regenerated', 'success');
    json_response(['ok' => true, 'recovery_codes' => $codes, 'two_factor' => admin_twofa_public_status($pdo, (int)$admin['id']), 'csrf_token' => csrf_token()]);
  }

  if ($action === '2fa_disable') {
    $admin = require_admin();
    $currentPassword = (string)($input['current_password'] ?? '');
    $code = (string)($input['code'] ?? '');
    $row = admin_two_factor_row($pdo, (int)$admin['id']);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    $verifiedCode = $secret !== '' && verify_and_consume_totp($pdo, 'admin_two_factor', 'admin_id', (int)$admin['id'], $secret, $code);
    if (!$verifiedCode) {
      $verifiedCode = consume_admin_recovery_code($pdo, (int)$admin['id'], $code);
    }
    if (!verify_current_admin_password($pdo, (int)$admin['id'], $currentPassword) || !$verifiedCode) {
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_disable_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Potvrda nije ispravna.'], 401);
    }
    $pdo->prepare('UPDATE admin_two_factor SET secret_encrypted = NULL, pending_secret_encrypted = NULL, enabled_at = NULL, disabled_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([(int)$admin['id']]);
    $pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([(int)$admin['id']]);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_two_factor_disabled', 'success');
    json_response(['ok' => true, 'two_factor' => admin_twofa_public_status($pdo, (int)$admin['id']), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'passkey_registration_options') {
    require_webauthn_runtime();
    $admin = require_admin();
    require_admin_passkey_step_up($pdo, $admin, $input);
    $stmt = $pdo->prepare('SELECT credential_id FROM owner_passkeys WHERE admin_id = ? AND revoked_at IS NULL');
    $stmt->execute([(int)$admin['id']]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $binaryIds = array_map(static function ($id): string {
      $value = base64_decode(strtr((string)$id, '-_', '+/') . str_repeat('=', (4 - strlen((string)$id) % 4) % 4), true);
      return is_string($value) ? $value : '';
    }, $ids);
    $challenge = webauthn_new_challenge();
    $options = webauthn_creation_options($admin, $challenge, array_filter($binaryIds));
    webauthn_store_challenge($pdo, 'registration', $challenge, $options);
    json_response(['ok' => true, 'public_key' => webauthn_json_options($options), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'passkey_registration_verify') {
    require_webauthn_runtime();
    $admin = require_admin();
    $responseJson = (string)($input['credential_json'] ?? '');
    if ($responseJson === '' || strlen($responseJson) > MAX_JSON_BODY_BYTES) json_response(['ok' => false, 'error' => 'Passkey registracija nije validna.'], 400);
    $challengeKey = webauthn_response_challenge_key($responseJson);
    $challenge = webauthn_take_challenge($pdo, 'registration', $challengeKey);
    if (!$challenge) json_response(['ok' => false, 'error' => 'Passkey zahtev je istekao. Pokrenite registraciju ponovo.'], 401);
    $options = webauthn_serializer()->deserialize((string)$challenge['options_json'], Webauthn\PublicKeyCredentialCreationOptions::class, 'json');
    if (!$options instanceof Webauthn\PublicKeyCredentialCreationOptions) throw new RuntimeException('Passkey opcije nisu validne.');
    $record = webauthn_validate_registration($responseJson, $options);
    $credentialId = rtrim(strtr(base64_encode($record->publicKeyCredentialId), '+/', '-_'), '=');
    $label = trim((string)($input['label'] ?? 'Passkey'));
    $label = substr($label !== '' ? $label : 'Passkey', 0, 120);
    $pdo->prepare('INSERT INTO owner_passkeys (admin_id, credential_id, credential_record_json, label, sign_count) VALUES (?, ?, ?, ?, ?)')
      ->execute([(int)$admin['id'], $credentialId, webauthn_serializer()->serialize($record, 'json'), $label, $record->counter]);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_passkey_registered', 'success', ['passkey_id' => (int)$pdo->lastInsertId()]);
    json_response(['ok' => true, 'passkeys' => [], 'csrf_token' => csrf_token()]);
  }

  if ($action === 'passkey_remove') {
    $admin = require_admin();
    require_admin_passkey_step_up($pdo, $admin, $input);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Passkey nije pronađen.'], 404);
    $stmt = $pdo->prepare('UPDATE owner_passkeys SET revoked_at = NOW() WHERE id = ? AND admin_id = ? AND revoked_at IS NULL');
    $stmt->execute([$id, (int)$admin['id']]);
    if ($stmt->rowCount() !== 1) json_response(['ok' => false, 'error' => 'Passkey nije pronađen.'], 404);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_passkey_revoked', 'success', ['passkey_id' => $id]);
    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  if ($action === 'change_password') {
    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword = (string)($input['new_password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');
    $admin = require_admin();

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
      json_response(['ok' => false, 'error' => 'Popunite trenutnu šifru, novu šifru i potvrdu.'], 400);
    }
    if (strlen($newPassword) < 8) {
      json_response(['ok' => false, 'error' => 'Nova admin šifra mora imati najmanje 8 karaktera.'], 400);
    }
    if ($newPassword !== $confirmPassword) {
      json_response(['ok' => false, 'error' => 'Nova šifra i potvrda se ne poklapaju.'], 400);
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([(int)$admin['id'], 'active']);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPassword, (string)$hash)) {
      json_response(['ok' => false, 'error' => 'Trenutna admin šifra nije tačna.'], 401);
    }

    $fields = ['password_hash = ?'];
    if (has_column($pdo, 'admin_users', 'updated_at')) {
      $fields[] = 'updated_at = NOW()';
    }
    $update = $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$admin['id']]);
    audit_event($pdo, 'admin', (int)$admin['id'], 'admin_password_changed', 'success');

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  if ($action === 'resend_payment_notice') {
    require_recent_admin_step_up();
    $noticeId = (int)($input['id'] ?? 0);
    if ($noticeId <= 0) json_response(['ok' => false, 'error' => 'Nedostaje uplata.'], 400);

    $stmt = $pdo->prepare('SELECT * FROM payment_notice_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$noticeId]);
    $notice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$notice) json_response(['ok' => false, 'error' => 'Obaveštenje o uplati nije pronađeno.'], 404);

    $recipients = notification_recipients('mail.payment_notice_to', [
      'arsenijee19@gmail.com',
      'support@licenca.rs',
    ]);
    $subject = 'Reseller je označio uplatu';
    $message = "Reseller je kliknuo dugme \"Uplatio sam\" i označio da je izvršio uplatu.\n\n";
    $message .= 'Reseller ID: ' . (int)$notice['reseller_id'] . "\n";
    $message .= 'Reseller Email: ' . (string)$notice['reseller_email'] . "\n";
    $message .= 'Balance u trenutku klika: ' . (int)$notice['balance_rsd'] . " RSD\n";
    $message .= 'Vreme klika: ' . (string)$notice['clicked_at'] . " UTC\n\n";
    $message .= "Potrebno je proveriti uplatu i po potrebi ažurirati balance u admin panelu.\n";

    $result = send_text_notification_email($recipients, $subject, $message);
    $update = $pdo->prepare('UPDATE payment_notice_requests SET status = ?, recipients = ?, attempts = attempts + 1, error_message = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([
      $result['ok'] ? 'sent' : 'failed',
      implode(', ', $recipients),
      $result['ok'] ? null : safe_public_error((string)$result['error']),
      $noticeId,
    ]);
    audit_event($pdo, 'admin', (int)$admin['id'], 'payment_notice_resent', $result['ok'] ? 'success' : 'notification_failed', [
      'notice_id' => $noticeId,
      'notification_sent' => $result['ok'],
    ]);

    $payload = dashboard_payload($pdo, $input['filters'] ?? []);
    $payload['notification'] = ['sent' => $result['ok'], 'notice_id' => $noticeId];
    json_response($payload);
  }

  if ($action === 'resend_order_email') {
    require_recent_admin_step_up();
    $orderId = (int)($input['id'] ?? 0);
    if ($orderId <= 0) json_response(['ok' => false, 'error' => 'Nedostaje porudžbina.'], 400);

    $stmt = $pdo->prepare("
      SELECT o.*, pp.product_name, pp.account_type, r.display_name AS reseller_name, r.phone AS reseller_phone
      FROM orders o
      LEFT JOIN product_prices pp ON pp.product_id = o.product_id
      LEFT JOIN resellers r ON r.id = o.reseller_id
      WHERE o.id = ?
      LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);

    $notification = order_notification($order);
    $result = send_text_notification_email($notification['recipients'], $notification['subject'], $notification['message']);
    try {
      record_order_delivery_event($pdo, $orderId, 'email', $result['ok'] ? 'sent' : 'failed', [
        'recipients' => $result['recipients'],
        'error' => $result['ok'] ? '' : $result['error'],
      ]);
    } catch (Throwable $ignored) {}
    try {
      audit_event($pdo, 'admin', (int)$admin['id'], 'order_email_resent', $result['ok'] ? 'success' : 'notification_failed', [
        'order_id' => $orderId,
        'notification_sent' => $result['ok'],
      ]);
    } catch (Throwable $ignored) {}

    $payload = dashboard_payload($pdo, $input['filters'] ?? []);
    $payload['notification'] = ['sent' => $result['ok'], 'order_id' => $orderId];
    json_response($payload);
  }

  if ($action === 'save_inventory_config') {
    require_recent_admin_step_up();
    $apiBase = rtrim(h_string($input['api_base'] ?? ''), '/');
    $supplierToken = trim((string)($input['supplier_token'] ?? ''));

    if ($apiBase === '' || !preg_match('#^https?://#i', $apiBase)) {
      json_response(['ok' => false, 'error' => 'Unesite validan API Base URL, npr. https://baza.igreps.rs.'], 400);
    }
    if ($supplierToken === '' && inventory_supplier_token() === '') {
      json_response(['ok' => false, 'error' => 'Unesite supplier token.'], 400);
    }
    if ($supplierToken !== '' && strlen($supplierToken) < 24) {
      json_response(['ok' => false, 'error' => 'Supplier token deluje prekratko.'], 400);
    }

    $config = app_config();
    if (!is_array($config['inventory'] ?? null)) {
      $config['inventory'] = [];
    }
    $config['inventory']['api_base'] = $apiBase;
    if ($supplierToken !== '') {
      $config['inventory']['supplier_token'] = $supplierToken;
    }
    write_app_config($config);
    audit_event($pdo, 'admin', (int)($_SESSION['admin_id'] ?? 0), 'inventory_config_updated', 'success', [
      'api_base' => $apiBase,
      'token_changed' => $supplierToken !== '',
    ]);

    json_response(dashboard_payload($pdo));
  }

  if ($action === 'update_reseller') {
    require_recent_admin_step_up();
    $id = (int)($input['id'] ?? 0);
    $displayName = trim((string)($input['display_name'] ?? ''));
    $email = h_string($input['email'] ?? '');
    $phone = normalize_phone((string)($input['phone'] ?? ''));
    $status = h_string($input['status'] ?? 'active');
    $balance = (int)($input['balance_rsd'] ?? 0);
    $newToken = (string)($input['new_token'] ?? '');

    if ($id <= 0 || !valid_email($email)) {
      json_response(['ok' => false, 'error' => 'Neispravan reseller.'], 400);
    }
    if ($displayName !== '' && strlen($displayName) > 120) {
      json_response(['ok' => false, 'error' => 'Ime je predugačko.'], 400);
    }

    $pdo->beginTransaction();
    $old = $pdo->prepare('SELECT balance_rsd FROM resellers WHERE id = ? LIMIT 1');
    $old->execute([$id]);
    $oldBalance = $old->fetchColumn();
    if ($oldBalance === false) {
      throw new RuntimeException('Reseller nije pronađen.');
    }

    $fields = ['email = ?', 'status = ?', 'balance_rsd = ?'];
    $params = [$email, $status, $balance];
    if (has_column($pdo, 'resellers', 'display_name')) {
      $fields[] = 'display_name = ?';
      $params[] = $displayName !== '' ? $displayName : null;
    }
    if (has_column($pdo, 'resellers', 'phone')) {
      if ($phone !== '' && !valid_phone($phone)) {
        throw new RuntimeException('Telefon nije validan.');
      }
      $fields[] = 'phone = ?';
      $params[] = $phone;
    }
    if ($newToken !== '') {
      if (strlen($newToken) < 12) {
        throw new RuntimeException('Nova šifra/token mora imati najmanje 12 karaktera.');
      }
      $lowerToken = strtolower($newToken);
      if (
        in_array($lowerToken, ['password', '123456789012', 'playworld123', 'reseller1234'], true) ||
        strpos($lowerToken, strtolower($email)) !== false ||
        ($phone !== '' && strpos(preg_replace('/\D+/', '', $newToken), preg_replace('/\D+/', '', $phone)) !== false)
      ) {
        throw new RuntimeException('Nova šifra/token je previše laka za pogoditi.');
      }
      $fields[] = 'token_hash = ?';
      $params[] = password_hash($newToken, PASSWORD_DEFAULT);
    }
    if (has_column($pdo, 'resellers', 'updated_at')) {
      $fields[] = 'updated_at = NOW()';
    }
    $params[] = $id;

    $stmt = $pdo->prepare('UPDATE resellers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);
    audit_event($pdo, 'admin', (int)($_SESSION['admin_id'] ?? 0), 'reseller_updated', 'success', [
      'reseller_id' => $id,
      'credential_changed' => $newToken !== '',
    ]);

    $diff = $balance - (int)$oldBalance;
    if ($diff !== 0) {
      $tx = $pdo->prepare("INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description) VALUES (?, 'ADMIN_ADJUSTMENT', ?, ?)");
      $tx->execute([$id, $diff, 'Admin balance adjustment']);
    }

    $pdo->commit();
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'create_reseller') {
    $email = normalize_email((string)($input['email'] ?? ''));
    $phone = normalize_phone((string)($input['phone'] ?? ''));
    $displayName = trim((string)($input['display_name'] ?? ''));
    $status = h_string($input['status'] ?? 'active') ?: 'active';
    $balance = (int)($input['balance_rsd'] ?? 0);
    $token = (string)($input['token'] ?? '');

    if ($displayName === '') {
      json_response(['ok' => false, 'error' => 'Unesite ime resellera.'], 400);
    }
    if ($displayName !== '' && strlen($displayName) > 120) {
      json_response(['ok' => false, 'error' => 'Ime je predugačko.'], 400);
    }
    if ($email === '') {
      $email = temporary_reseller_email($displayName);
    }
    if (!valid_email($email)) {
      json_response(['ok' => false, 'error' => 'Email nije validan. Ostavite prazno ili unesite lični email resellera.'], 400);
    }
    if (is_internal_reseller_email($email)) {
      $email = temporary_reseller_email($displayName);
    }
    if ($phone !== '' && !valid_phone($phone)) {
      json_response(['ok' => false, 'error' => 'Telefon nije validan.'], 400);
    }
    if (strlen($token) < 12) {
      json_response(['ok' => false, 'error' => 'Početni token mora imati najmanje 12 karaktera.'], 400);
    }
    $lowerToken = strtolower($token);
    if (
      in_array($lowerToken, ['password', '123456789012', 'playworld123', 'reseller1234'], true) ||
      strpos($lowerToken, strtolower($email)) !== false ||
      ($phone !== '' && strpos(preg_replace('/\D+/', '', $token), preg_replace('/\D+/', '', $phone)) !== false)
    ) {
      json_response(['ok' => false, 'error' => 'Početni token je previše lak za pogoditi.'], 400);
    }

    $exists = $pdo->prepare('SELECT COUNT(*) FROM resellers WHERE email = ?');
    $exists->execute([$email]);
    if ((int)$exists->fetchColumn() > 0) {
      json_response(['ok' => false, 'error' => 'Reseller sa tim emailom već postoji.'], 409);
    }

    $columns = column_names($pdo, 'resellers');
    $fields = ['email', 'token_hash', 'status', 'balance_rsd'];
    $params = [$email, password_hash($token, PASSWORD_DEFAULT), $status, $balance];
    if (in_array('phone', $columns, true)) {
      $fields[] = 'phone';
      $params[] = $phone !== '' ? $phone : null;
    }
    if (in_array('display_name', $columns, true)) {
      $fields[] = 'display_name';
      $params[] = $displayName !== '' ? $displayName : null;
    }
    if (in_array('profile_completed_at', $columns, true) && $phone !== '' && !is_internal_reseller_email($email)) {
      $fields[] = 'profile_completed_at';
      $params[] = date('Y-m-d H:i:s');
    }
    if (in_array('updated_at', $columns, true)) {
      $fields[] = 'updated_at';
      $params[] = date('Y-m-d H:i:s');
    }

    $sql = 'INSERT INTO resellers (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $newId = (int)$pdo->lastInsertId();

    if ($balance !== 0) {
      $tx = $pdo->prepare("INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description) VALUES (?, 'ADMIN_ADJUSTMENT', ?, ?)");
      $tx->execute([$newId, $balance, 'Initial reseller balance']);
    }
    audit_event($pdo, 'admin', (int)($_SESSION['admin_id'] ?? 0), 'reseller_created', 'success', [
      'reseller_id' => $newId,
      'has_phone' => $phone !== '',
      'has_display_name' => $displayName !== '',
    ]);

    json_response(dashboard_payload($pdo));
  }

  if ($action === 'save_product') {
    $mode = h_string($input['mode'] ?? 'update');
    $originalId = h_string($input['original_product_id'] ?? '');
    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    $columns = table_columns($pdo, 'product_prices');
    $allowed = array_column($columns, 'COLUMN_NAME');
    $auto = [];
    foreach ($columns as $column) {
      if (strpos((string)$column['EXTRA'], 'auto_increment') !== false) {
        $auto[] = $column['COLUMN_NAME'];
      }
    }

    $clean = [];
    foreach ($fields as $key => $value) {
      if (in_array($key, $allowed, true) && !in_array($key, $auto, true)) {
        $clean[$key] = clean_admin_value($value);
      }
    }

    if (empty($clean['product_id'])) {
      json_response(['ok' => false, 'error' => 'Product ID je obavezan.'], 400);
    }

    if ($mode === 'create') {
      $exists = $pdo->prepare('SELECT COUNT(*) FROM product_prices WHERE product_id = ?');
      $exists->execute([$clean['product_id']]);
      if ((int)$exists->fetchColumn() > 0) {
        json_response(['ok' => false, 'error' => 'Proizvod sa tim Product ID već postoji. Izmeni postojeći proizvod ili koristi drugi ID.'], 409);
      }

      foreach ($columns as $column) {
        $name = (string)$column['COLUMN_NAME'];
        if (($clean[$name] ?? '') === '' && ($column['IS_NULLABLE'] === 'YES' || $column['COLUMN_DEFAULT'] !== null || $name === 'updated_at')) {
          unset($clean[$name]);
        }
      }
      $keys = array_keys($clean);
      $sql = 'INSERT INTO product_prices (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
      $stmt = $pdo->prepare($sql);
      $stmt->execute(array_values($clean));
    } else {
      if ($originalId === '') {
        json_response(['ok' => false, 'error' => 'Nedostaje originalni Product ID.'], 400);
      }
      $sets = [];
      $params = [];
      foreach ($clean as $key => $value) {
        $sets[] = "`$key` = ?";
        $params[] = $value;
      }
      $params[] = $originalId;
      $stmt = $pdo->prepare('UPDATE product_prices SET ' . implode(', ', $sets) . ' WHERE product_id = ?');
      $stmt->execute($params);
    }

    json_response(dashboard_payload($pdo));
  }

  if ($action === 'deactivate_product') {
    $productId = h_string($input['product_id'] ?? '');
    if ($productId === '') json_response(['ok' => false, 'error' => 'Nedostaje proizvod.'], 400);

    if (has_column($pdo, 'product_prices', 'status')) {
      $stmt = $pdo->prepare("UPDATE product_prices SET status = 'inactive' WHERE product_id = ?");
      $stmt->execute([$productId]);
    } elseif (has_column($pdo, 'product_prices', 'is_active')) {
      $stmt = $pdo->prepare('UPDATE product_prices SET is_active = 0 WHERE product_id = ?');
      $stmt->execute([$productId]);
    } else {
      $stmt = $pdo->prepare('DELETE FROM product_prices WHERE product_id = ?');
      $stmt->execute([$productId]);
    }
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'delete_product') {
    $productId = h_string($input['product_id'] ?? '');
    if ($productId === '') json_response(['ok' => false, 'error' => 'Nedostaje proizvod.'], 400);
    $stmt = $pdo->prepare('DELETE FROM product_prices WHERE product_id = ?');
    $stmt->execute([$productId]);
    json_response(dashboard_payload($pdo));
  }

  if ($action === 'update_order') {
    $id = (int)($input['id'] ?? 0);
    $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Nedostaje porudžbina.'], 400);

    $allowed = array_intersect(
      ['status', 'admin_notes', 'reseller_notes', 'reseller_paid', 'reseller_paid_at', 'delivery_payload'],
      column_names($pdo, 'orders')
    );
    $clean = [];
    foreach ($fields as $key => $value) {
      if (in_array($key, $allowed, true)) {
        $clean[$key] = is_string($value) ? trim($value) : $value;
      }
    }
    if (!$clean) json_response(['ok' => false, 'error' => 'Nema polja za izmenu.'], 400);

    $current = $pdo->prepare('SELECT status, canceled_at FROM orders WHERE id = ? LIMIT 1');
    $current->execute([$id]);
    $currentOrder = $current->fetch(PDO::FETCH_ASSOC);
    if (!$currentOrder) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);

    $isCanceled = (string)($currentOrder['canceled_at'] ?? '') !== ''
      || in_array(strtolower((string)($currentOrder['status'] ?? '')), ['canceled', 'cancelled'], true);
    if ($isCanceled && array_key_exists('status', $clean) && !in_array(strtolower((string)$clean['status']), ['canceled', 'cancelled'], true)) {
      json_response(['ok' => false, 'error' => 'Poništena porudžbina ne može ponovo da postane aktivna.'], 409);
    }

    $sets = [];
    $params = [];
    foreach ($clean as $key => $value) {
      $sets[] = "`$key` = ?";
      $params[] = $value;
    }
    if (has_column($pdo, 'orders', 'updated_at')) {
      $sets[] = 'updated_at = NOW()';
    }
    $params[] = $id;
    $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    audit_event($pdo, 'admin', (int)($_SESSION['admin_id'] ?? 0), 'order_updated', 'success', [
      'order_id' => $id,
      'fields' => array_keys($clean),
    ]);
    json_response(dashboard_payload($pdo, $input['filters'] ?? []));
  }

  if ($action === 'cancel_order') {
    require_recent_admin_step_up();
    $id = (int)($input['id'] ?? 0);
    $reason = trim((string)($input['reason'] ?? ''));
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Nedostaje porudžbina.'], 400);
    if (strlen($reason) > 500) json_response(['ok' => false, 'error' => 'Razlog je predugačak.'], 400);

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare('SELECT id, reseller_id, product_id, price_rsd, status, canceled_at FROM orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
    }

    $isCanceled = (string)($order['canceled_at'] ?? '') !== ''
      || in_array(strtolower((string)($order['status'] ?? '')), ['canceled', 'cancelled'], true);
    if ($isCanceled) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Ova porudžbina je već poništena i novac je već vraćen.'], 409);
    }

    if (!has_column($pdo, 'wallet_transactions', 'related_order_id')) {
      throw new RuntimeException('Nije moguće bezbedno poništiti porudžbinu: wallet veza sa porudžbinom ne postoji.');
    }

    $resellerStmt = $pdo->prepare('SELECT id, balance_rsd FROM resellers WHERE id = ? FOR UPDATE');
    $resellerStmt->execute([(int)$order['reseller_id']]);
    $reseller = $resellerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reseller) throw new RuntimeException('Reseller porudžbine nije pronađen.');

    $chargeStmt = $pdo->prepare("SELECT id, amount_rsd FROM wallet_transactions WHERE reseller_id = ? AND related_order_id = ? AND type = 'ORDER' ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $chargeStmt->execute([(int)$order['reseller_id'], $id]);
    $charge = $chargeStmt->fetch(PDO::FETCH_ASSOC);
    $chargedAmount = (int)($charge['amount_rsd'] ?? 0);
    $orderPrice = (int)$order['price_rsd'];
    if (!$charge || $chargedAmount >= 0 || abs($chargedAmount) !== $orderPrice) {
      throw new RuntimeException('Nije moguće bezbedno poništiti porudžbinu: originalno zaduženje nije jednoznačno.');
    }

    $reversalType = order_reversal_transaction_type($pdo);
    $reversalStmt = $pdo->prepare('SELECT id FROM wallet_transactions WHERE reseller_id = ? AND related_order_id = ? AND type = ? LIMIT 1 FOR UPDATE');
    $reversalStmt->execute([(int)$order['reseller_id'], $id, $reversalType]);
    if ($reversalStmt->fetchColumn()) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Ova porudžbina je već finansijski poništena.'], 409);
    }

    $refund = abs($chargedAmount);
    $newBalance = (int)$reseller['balance_rsd'] + $refund;
    $pdo->prepare('UPDATE resellers SET balance_rsd = ? WHERE id = ?')->execute([$newBalance, (int)$order['reseller_id']]);

    $description = 'Order reversal #' . $id . ($reason !== '' ? ': ' . $reason : '');
    $wallet = $pdo->prepare('INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description, related_order_id) VALUES (?, ?, ?, ?, ?)');
    $wallet->execute([(int)$order['reseller_id'], $reversalType, $refund, $description, $id]);

    $orderUpdate = $pdo->prepare('UPDATE orders SET status = ?, canceled_at = NOW(), canceled_by_admin_id = ?, cancellation_reason = ?, updated_at = NOW() WHERE id = ?');
    $orderUpdate->execute(['canceled', $adminId, $reason !== '' ? $reason : null, $id]);
    audit_event($pdo, 'admin', $adminId, 'order_canceled', 'success', [
      'order_id' => $id,
      'reseller_id' => (int)$order['reseller_id'],
      'refund_rsd' => $refund,
      'reason_provided' => $reason !== '',
    ]);
    $pdo->commit();

    $payload = dashboard_payload($pdo, $input['filters'] ?? []);
    $payload['cancellation'] = [
      'order_id' => $id,
      'refund_rsd' => $refund,
      'balance_rsd' => $newBalance,
    ];
    json_response($payload);
  }

  json_response(['ok' => false, 'error' => 'Unknown action'], 404);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => public_error_detail($e)], 500);
}
