<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/device_auth_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
require_same_origin();
require_json_content_type();

$input = read_json_body();
$action = h_string($_GET['action'] ?? '');
$pdo = db();

if (!table_exists($pdo, 'reseller_device_activation_codes') || !table_exists($pdo, 'reseller_app_devices')) {
  json_response(['ok' => false, 'error' => 'Prijava aplikacije još nije aktivirana na serveru.'], 503);
}

function device_auth_rate_limit(PDO $pdo): void {
  enforce_rate_limit(
    $pdo,
    'reseller_app_device_ip',
    client_ip(),
    30,
    900,
    'Previše pokušaja prijave sa ove mreže. Sačekajte nekoliko minuta.'
  );
}

function open_reseller_device_session(int $resellerId, string $email, string $deviceId): array {
  start_secure_session();
  if ((int)($_SESSION['reseller_id'] ?? 0) === $resellerId
    && (string)($_SESSION['app_device_id'] ?? '') === $deviceId
    && !empty($_SESSION['csrf_token'])) {
    return ['csrf_token' => (string)$_SESSION['csrf_token']];
  }
  session_regenerate_id(true);
  $_SESSION = [];
  $_SESSION['reseller_id'] = $resellerId;
  $_SESSION['reseller_email'] = $email;
  $_SESSION['app_device_id'] = $deviceId;
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  $_SESSION['created_at'] = time();
  $_SESSION['last_activity'] = time();
  bind_session_to_credential(db(), 'reseller');
  return ['csrf_token' => (string)$_SESSION['csrf_token']];
}

function open_admin_device_session(int $adminId, string $username, string $deviceId): void {
  start_secure_session('admin');
  if ((int)($_SESSION['admin_id'] ?? 0) === $adminId
    && (string)($_SESSION['app_device_id'] ?? '') === $deviceId
    && !empty($_SESSION['csrf_token'])) {
    return;
  }
  session_regenerate_id(true);
  $_SESSION = [];
  $_SESSION['admin_id'] = $adminId;
  $_SESSION['admin_username'] = $username;
  $_SESSION['app_device_id'] = $deviceId;
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  $_SESSION['created_at'] = time();
  $_SESSION['last_activity'] = time();
  bind_session_to_credential(db(), 'admin');
  // Deliberately no admin_step_up_until: critical admin actions still ask for the password (and 2FA).
}

/**
 * Admin one-time code path. Returns false when the code is not an admin code so the
 * caller can report the usual "invalid code" error. Responds and exits otherwise.
 */
function admin_device_try_activate(PDO $pdo, string $code, string $deviceId, string $platform, string $deviceName, string $credentialHash): bool {
  if (!table_exists($pdo, 'admin_device_activation_codes') || !table_exists($pdo, 'admin_app_devices')) return false;
  $pdo->beginTransaction();
  $codeStmt = $pdo->prepare("SELECT c.id, c.admin_id, a.username
    FROM admin_device_activation_codes c
    INNER JOIN admin_users a ON a.id = c.admin_id AND a.status = 'active'
    WHERE c.code_hash = ? AND c.consumed_at IS NULL AND c.revoked_at IS NULL AND c.expires_at > NOW()
    LIMIT 1 FOR UPDATE");
  $codeStmt->execute([hash('sha256', $code)]);
  $activation = $codeStmt->fetch(PDO::FETCH_ASSOC);
  if (!$activation) {
    $pdo->rollBack();
    return false;
  }
  $adminId = (int)$activation['admin_id'];
  $activeCount = $pdo->prepare('SELECT COUNT(*) FROM admin_app_devices WHERE admin_id = ? AND revoked_at IS NULL FOR UPDATE');
  $activeCount->execute([$adminId]);
  if ((int)$activeCount->fetchColumn() >= 5) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => 'Admin nalog već ima 5 aktivnih uređaja. Uklonite neki stari uređaj u admin panelu.'], 409);
  }
  $consume = $pdo->prepare('UPDATE admin_device_activation_codes SET consumed_at = NOW(), consumed_device_id = ? WHERE id = ? AND consumed_at IS NULL AND revoked_at IS NULL');
  $consume->execute([$deviceId, (int)$activation['id']]);
  if ($consume->rowCount() !== 1) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => 'Kod je već iskorišćen. Napravite novi kod u admin panelu.'], 409);
  }
  $insert = $pdo->prepare('INSERT INTO admin_app_devices (admin_id, device_id, device_name, platform, credential_hash, last_seen_at, last_ip_address, user_agent) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)');
  $insert->execute([$adminId, $deviceId, $deviceName, $platform, $credentialHash, client_ip(), user_agent()]);
  $pdo->commit();

  open_admin_device_session($adminId, (string)$activation['username'], $deviceId);
  try {
    audit_event($pdo, 'admin', $adminId, 'admin_app_device_activated', 'success', ['device_id' => $deviceId, 'platform' => $platform]);
  } catch (Throwable $loggingError) {
    error_log('admin_app_device_activation_audit_failed');
  }
  device_auth_finalize_session_cookie();
  json_response(['ok' => true, 'role' => 'admin', 'device_id' => $deviceId, 'csrf_token' => csrf_token()]);
}

/** Session renewal / logout for admin devices. Returns false if the credential is not an admin device. */
function admin_device_try_session(PDO $pdo, string $action, string $deviceId, string $credential): bool {
  if (!table_exists($pdo, 'admin_app_devices')) return false;
  revoke_idle_admin_devices($pdo);
  $stmt = $pdo->prepare("SELECT d.id, d.admin_id, a.username
    FROM admin_app_devices d INNER JOIN admin_users a ON a.id = d.admin_id AND a.status = 'active'
    WHERE d.device_id = ? AND d.credential_hash = ? AND d.revoked_at IS NULL LIMIT 1");
  $stmt->execute([$deviceId, app_device_credential_hash($credential)]);
  $device = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$device) return false;

  if ($action === 'logout') {
    $pdo->prepare('UPDATE admin_app_devices SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([(int)$device['id']]);
    audit_event($pdo, 'admin', (int)$device['admin_id'], 'admin_app_device_logged_out', 'success', ['device_id' => $deviceId]);
    json_response(['ok' => true]);
  }

  $pdo->prepare('UPDATE admin_app_devices SET last_seen_at = NOW(), last_ip_address = ?, user_agent = ? WHERE id = ?')
    ->execute([client_ip(), user_agent(), (int)$device['id']]);
  open_admin_device_session((int)$device['admin_id'], (string)$device['username'], $deviceId);
  device_auth_finalize_session_cookie();
  json_response(['ok' => true, 'role' => 'admin', 'device_id' => $deviceId, 'csrf_token' => csrf_token()]);
}

function normalize_app_device_id(string $deviceId): string {
  $deviceId = strtolower(trim($deviceId));
  return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $deviceId)
    ? $deviceId
    : '';
}

try {
  // Full schema checks are only needed for first-time activation. Session
  // renewals are frequent and should not perform unrelated DDL work.
  if ($action === 'activate') ensure_security_tables($pdo);
  device_auth_rate_limit($pdo);

  if ($action === 'activate') {
    $code = normalize_app_activation_code((string)($input['code'] ?? ''));
    $deviceId = normalize_app_device_id((string)($input['device_id'] ?? ''));
    $platform = strtolower(h_string($input['platform'] ?? ''));
    $deviceName = trim((string)($input['device_name'] ?? ''));
    $credential = trim((string)($input['device_token'] ?? ''));
    if ($code === '' || $deviceId === '' || !in_array($platform, ['android', 'ios'], true)
      || !preg_match('/^[A-Za-z0-9_-]{43}$/', $credential)) {
      json_response(['ok' => false, 'error' => 'Proverite aktivacioni kod i pokušajte ponovo.'], 400);
    }
    $deviceName = preg_replace('/[\x00-\x1F\x7F]/u', '', $deviceName) ?: '';
    $deviceName = function_exists('mb_substr') ? mb_substr(trim($deviceName), 0, 120) : substr(trim($deviceName), 0, 120);
    if ($deviceName === '') $deviceName = $platform === 'ios' ? 'iPhone / iPad' : 'Android uređaj';

    $credentialHash = app_device_credential_hash($credential);
    $pdo->beginTransaction();
    $codeStmt = $pdo->prepare("SELECT c.id, c.reseller_id
      FROM reseller_device_activation_codes c
      WHERE c.code_hash = ? AND c.consumed_at IS NULL AND c.revoked_at IS NULL AND c.expires_at > NOW()
      LIMIT 1 FOR UPDATE");
    $codeStmt->execute([hash('sha256', $code)]);
    $activation = $codeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$activation) {
      $pdo->rollBack();
      if (admin_device_try_activate($pdo, $code, $deviceId, $platform, $deviceName, $credentialHash)) exit;
      record_login_attempt($pdo, 'reseller_app_activation', client_ip(), false);
      json_response(['ok' => false, 'error' => 'Kod nije važeći ili je istekao. Zatražite novi kod od administratora.'], 401);
    }

    $resellerId = (int)$activation['reseller_id'];
    $resellerStmt = $pdo->prepare("SELECT id, email, status FROM resellers WHERE id = ? LIMIT 1 FOR UPDATE");
    $resellerStmt->execute([$resellerId]);
    $reseller = $resellerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reseller || strtolower((string)$reseller['status']) !== 'active') {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Reseller nalog nije aktivan. Kontaktirajte administratora.'], 403);
    }
    $activeCount = $pdo->prepare('SELECT COUNT(*) FROM reseller_app_devices WHERE reseller_id = ? AND revoked_at IS NULL FOR UPDATE');
    $activeCount->execute([$resellerId]);
    if ((int)$activeCount->fetchColumn() >= 10) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Nalog već ima 10 aktivnih uređaja. Zamolite administratora da ukloni neki stari uređaj.'], 409);
    }

    $consume = $pdo->prepare('UPDATE reseller_device_activation_codes SET consumed_at = NOW(), consumed_device_id = ? WHERE id = ? AND consumed_at IS NULL AND revoked_at IS NULL');
    $consume->execute([$deviceId, (int)$activation['id']]);
    if ($consume->rowCount() !== 1) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => 'Kod je već iskorišćen. Zatražite novi kod od administratora.'], 409);
    }
    $insert = $pdo->prepare('INSERT INTO reseller_app_devices (reseller_id, device_id, device_name, platform, credential_hash, last_seen_at, last_ip_address, user_agent) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)');
    $insert->execute([$resellerId, $deviceId, $deviceName, $platform, $credentialHash, client_ip(), user_agent()]);
    $pdo->commit();

    open_reseller_device_session($resellerId, (string)$reseller['email'], $deviceId);
    try {
      record_login_attempt($pdo, 'reseller_app_activation', 'account:' . $resellerId, true);
      audit_event($pdo, 'reseller', $resellerId, 'app_device_activated', 'success', ['device_id' => $deviceId, 'platform' => $platform]);
    } catch (Throwable $loggingError) {
      error_log('app_device_activation_audit_failed reseller_id=' . $resellerId);
    }
    device_auth_finalize_session_cookie();
    json_response(['ok' => true, 'role' => 'reseller', 'device_id' => $deviceId, 'csrf_token' => csrf_token()]);
  }

  if ($action === 'session' || $action === 'logout') {
    $deviceId = normalize_app_device_id((string)($input['device_id'] ?? ''));
    $credential = trim((string)($input['device_token'] ?? ''));
    if ($deviceId === '' || !preg_match('/^[A-Za-z0-9_-]{40,50}$/', $credential)) {
      json_response(['ok' => false, 'error' => 'Prijava ovog uređaja više nije važeća. Zatražite novi kod od administratora.'], 401);
    }
    $deviceStmt = $pdo->prepare("SELECT d.id, d.reseller_id, r.email, r.status
      FROM reseller_app_devices d INNER JOIN resellers r ON r.id = d.reseller_id
      WHERE d.device_id = ? AND d.credential_hash = ? AND d.revoked_at IS NULL LIMIT 1");
    $deviceStmt->execute([$deviceId, app_device_credential_hash($credential)]);
    $device = $deviceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$device && admin_device_try_session($pdo, $action, $deviceId, $credential)) exit;
    if (!$device || strtolower((string)$device['status']) !== 'active') {
      json_response(['ok' => false, 'error' => 'Prijava ovog uređaja je opozvana ili nalog nije aktivan. Zatražite novi kod od administratora.'], 401);
    }

    if ($action === 'logout') {
      $pdo->prepare('UPDATE reseller_app_devices SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')->execute([(int)$device['id']]);
      audit_event($pdo, 'reseller', (int)$device['reseller_id'], 'app_device_logged_out', 'success', ['device_id' => $deviceId]);
      json_response(['ok' => true]);
    }

    $pdo->prepare('UPDATE reseller_app_devices SET last_seen_at = NOW(), last_ip_address = ?, user_agent = ? WHERE id = ?')
      ->execute([client_ip(), user_agent(), (int)$device['id']]);
    open_reseller_device_session((int)$device['reseller_id'], (string)$device['email'], $deviceId);
    device_auth_finalize_session_cookie();
    json_response(['ok' => true, 'role' => 'reseller', 'device_id' => $deviceId, 'csrf_token' => csrf_token()]);
  }

  json_response(['ok' => false, 'error' => 'Nepoznata akcija.'], 404);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $reference = bin2hex(random_bytes(4));
  error_log('app_device_auth reference=' . $reference . ' class=' . get_class($e) . ' sqlstate=' . ($e instanceof PDOException ? (string)$e->getCode() : 'n/a'));
  json_response(['ok' => false, 'error' => 'Prijava uređaja trenutno nije uspela. Pokušajte ponovo.', 'reference' => $reference], 500);
}

function device_auth_finalize_session_cookie(): void {
  if (!ini_get('session.use_cookies') || session_id() === '') return;
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  // PHP may emit cookies both when starting and regenerating a session. Keep
  // only the final ID so native clients never store a stale session by accident.
  header_remove('Set-Cookie');
  setcookie(session_name(), session_id(), [
    'expires' => time() + SESSION_ABSOLUTE_LIFETIME_SECONDS,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
  ]);
}
