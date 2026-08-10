<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session();

$action = h_string($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

function require_post(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
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

  $sql = "
    SELECT o.*, pp.product_name, pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
  ";
  if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
  }
  $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT 250';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
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
    ],
    'resellers' => fetch_resellers($pdo),
    'products' => fetch_products($pdo),
    'orders' => fetch_orders($pdo, $filters),
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
      $_SESSION['pending_admin_id'] = (int)$admin['id'];
      $_SESSION['pending_admin_username'] = (string)$admin['username'];
      $_SESSION['pending_admin_2fa_expires_at'] = time() + 300;
      $_SESSION['pending_admin_2fa_attempts'] = 0;
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      audit_event($pdo, 'admin', (int)$admin['id'], 'admin_login_first_factor_success', 'success');
      json_response(['ok' => true, 'requires_2fa' => true, 'csrf_token' => csrf_token()]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_username'] = (string)$admin['username'];
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
    $ok = $secret !== '' && verify_totp_code($secret, $code);
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
    $_SESSION['admin_id'] = (int)$pending['id'];
    $_SESSION['admin_username'] = (string)$pending['username'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    $pdo->prepare('UPDATE admin_two_factor SET last_used_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([(int)$pending['id']]);
    audit_event($pdo, 'admin', (int)$pending['id'], $usedRecovery ? 'admin_two_factor_recovery_login' : 'admin_two_factor_success', 'success');

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  require_admin();

  if ($action === 'logout') {
    require_post();
    require_csrf();
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'], $_SESSION['pending_admin_2fa_expires_at'], $_SESSION['pending_admin_2fa_attempts']);
    json_response(['ok' => true]);
  }

  if ($action === 'dashboard') {
    json_response(dashboard_payload($pdo, $_GET));
  }

  if ($action === '2fa_status') {
    $admin = require_admin();
    json_response(['ok' => true, 'two_factor' => admin_twofa_public_status($pdo, (int)$admin['id']), 'csrf_token' => csrf_token()]);
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
    if (!verify_current_admin_password($pdo, (int)$admin['id'], $currentPassword) || $secret === '' || !verify_totp_code($secret, $code)) {
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
    $verifiedCode = $secret !== '' && verify_totp_code($secret, $code);
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

  if ($action === 'save_inventory_config') {
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

    $allowed = array_diff(column_names($pdo, 'orders'), ['id']);
    $clean = [];
    foreach ($fields as $key => $value) {
      if (in_array($key, $allowed, true)) {
        $clean[$key] = is_string($value) ? trim($value) : $value;
      }
    }
    if (!$clean) json_response(['ok' => false, 'error' => 'Nema polja za izmenu.'], 400);

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

    json_response(dashboard_payload($pdo, $input['filters'] ?? []));
  }

  json_response(['ok' => false, 'error' => 'Unknown action'], 404);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
