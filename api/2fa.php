<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$pdo = db();
ensure_security_tables($pdo);
$action = h_string($_GET['action'] ?? '');

function twofa_public_status(PDO $pdo, int $resellerId): array {
  $row = two_factor_row($pdo, $resellerId);
  return [
    'enabled' => two_factor_enabled($pdo, $resellerId),
    'enabled_at' => (string)($row['enabled_at'] ?? ''),
    'last_used_at' => (string)($row['last_used_at'] ?? ''),
    'recovery_codes_regenerated_at' => (string)($row['recovery_codes_regenerated_at'] ?? ''),
    'recovery_codes_remaining' => recovery_code_count($pdo, $resellerId),
  ];
}

function require_post_2fa(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
}

try {
  if ($action === 'challenge') {
    require_post_2fa();
    require_csrf();
    $pending = require_2fa_pending();
    $input = read_json_body();
    $code = trim((string)($input['code'] ?? ''));

    enforce_rate_limit($pdo, 'reseller_2fa', 'acct:' . $pending['id'], 8, 900, 'Previše pokušaja. Sačekajte nekoliko minuta i pokušajte ponovo.');
    $_SESSION['pending_2fa_attempts'] = (int)($_SESSION['pending_2fa_attempts'] ?? 0) + 1;
    if ((int)$_SESSION['pending_2fa_attempts'] > 8) {
      record_login_attempt($pdo, 'reseller_2fa', 'acct:' . $pending['id'], false);
      json_response(['ok' => false, 'error' => 'Previše pokušaja. Ulogujte se ponovo.'], 429);
    }

    $row = two_factor_row($pdo, (int)$pending['id']);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    $ok = $secret !== '' && verify_totp_code($secret, $code);
    $usedRecovery = false;
    if (!$ok) {
      $ok = consume_recovery_code($pdo, (int)$pending['id'], $code);
      $usedRecovery = $ok;
    }

    record_login_attempt($pdo, 'reseller_2fa', 'acct:' . $pending['id'], $ok);
    if (!$ok) {
      audit_event($pdo, 'reseller', (int)$pending['id'], 'two_factor_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Kod nije ispravan ili je istekao. Pokušajte ponovo.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['reseller_id'] = (int)$pending['id'];
    $_SESSION['reseller_email'] = (string)$pending['email'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['pending_reseller_id'], $_SESSION['pending_reseller_email'], $_SESSION['pending_2fa_expires_at'], $_SESSION['pending_2fa_attempts']);
    $pdo->prepare('UPDATE reseller_two_factor SET last_used_at = NOW(), updated_at = NOW() WHERE reseller_id = ?')->execute([(int)$pending['id']]);
    audit_event($pdo, 'reseller', (int)$pending['id'], $usedRecovery ? 'two_factor_recovery_login' : 'two_factor_success', 'success');

    json_response(['ok' => true, 'csrf_token' => csrf_token()]);
  }

  $reseller = require_reseller();
  $resellerId = (int)$reseller['id'];

  if ($action === 'status') {
    json_response(['ok' => true, 'two_factor' => twofa_public_status($pdo, $resellerId), 'csrf_token' => csrf_token()]);
  }

  require_post_2fa();
  require_csrf();
  $input = read_json_body();

  if ($action === 'setup_start') {
    $currentToken = (string)($input['current_token'] ?? '');
    if (!verify_current_reseller_token($pdo, $resellerId, $currentToken)) {
      audit_event($pdo, 'reseller', $resellerId, 'two_factor_setup_start_failed', 'failed', ['reason' => 'bad_current_token']);
      json_response(['ok' => false, 'error' => 'Trenutni token nije tačan.'], 401);
    }
    $secret = generate_totp_secret();
    $encrypted = encrypt_secret($secret);
    $stmt = $pdo->prepare("
      INSERT INTO reseller_two_factor (reseller_id, pending_secret_encrypted)
      VALUES (?, ?)
      ON DUPLICATE KEY UPDATE pending_secret_encrypted = VALUES(pending_secret_encrypted), updated_at = NOW()
    ");
    $stmt->execute([$resellerId, $encrypted]);
    audit_event($pdo, 'reseller', $resellerId, 'two_factor_setup_started', 'success');
    json_response([
      'ok' => true,
      'manual_key' => $secret,
      'otpauth_uri' => otpauth_uri('PlayWorld Reseller', (string)$reseller['email'], $secret),
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($action === 'setup_confirm') {
    $code = (string)($input['code'] ?? '');
    $row = two_factor_row($pdo, $resellerId);
    $secret = decrypt_secret((string)($row['pending_secret_encrypted'] ?? ''));
    if ($secret === '' || !verify_totp_code($secret, $code)) {
      audit_event($pdo, 'reseller', $resellerId, 'two_factor_setup_confirm_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Kod nije ispravan ili je istekao.'], 401);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
      UPDATE reseller_two_factor
      SET secret_encrypted = pending_secret_encrypted,
          pending_secret_encrypted = NULL,
          enabled_at = COALESCE(enabled_at, NOW()),
          disabled_at = NULL,
          last_used_at = NOW(),
          updated_at = NOW()
      WHERE reseller_id = ?
    ");
    $stmt->execute([$resellerId]);
    $codes = generate_recovery_codes($pdo, $resellerId);
    $pdo->commit();
    audit_event($pdo, 'reseller', $resellerId, 'two_factor_enabled', 'success');
    json_response(['ok' => true, 'recovery_codes' => $codes, 'two_factor' => twofa_public_status($pdo, $resellerId), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'regenerate_recovery') {
    $currentToken = (string)($input['current_token'] ?? '');
    $code = (string)($input['code'] ?? '');
    $row = two_factor_row($pdo, $resellerId);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    if (!verify_current_reseller_token($pdo, $resellerId, $currentToken) || $secret === '' || !verify_totp_code($secret, $code)) {
      audit_event($pdo, 'reseller', $resellerId, 'recovery_codes_regenerate_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Potvrda nije ispravna.'], 401);
    }
    $codes = generate_recovery_codes($pdo, $resellerId);
    audit_event($pdo, 'reseller', $resellerId, 'recovery_codes_regenerated', 'success');
    json_response(['ok' => true, 'recovery_codes' => $codes, 'two_factor' => twofa_public_status($pdo, $resellerId), 'csrf_token' => csrf_token()]);
  }

  if ($action === 'disable') {
    $currentToken = (string)($input['current_token'] ?? '');
    $code = (string)($input['code'] ?? '');
    $row = two_factor_row($pdo, $resellerId);
    $secret = decrypt_secret((string)($row['secret_encrypted'] ?? ''));
    $verifiedCode = $secret !== '' && verify_totp_code($secret, $code);
    if (!$verifiedCode) {
      $verifiedCode = consume_recovery_code($pdo, $resellerId, $code);
    }
    if (!verify_current_reseller_token($pdo, $resellerId, $currentToken) || !$verifiedCode) {
      audit_event($pdo, 'reseller', $resellerId, 'two_factor_disable_failed', 'failed');
      json_response(['ok' => false, 'error' => 'Potvrda nije ispravna.'], 401);
    }
    $pdo->prepare('UPDATE reseller_two_factor SET secret_encrypted = NULL, pending_secret_encrypted = NULL, enabled_at = NULL, disabled_at = NOW(), updated_at = NOW() WHERE reseller_id = ?')->execute([$resellerId]);
    $pdo->prepare('DELETE FROM reseller_recovery_codes WHERE reseller_id = ?')->execute([$resellerId]);
    audit_event($pdo, 'reseller', $resellerId, 'two_factor_disabled', 'success');
    json_response(['ok' => true, 'two_factor' => twofa_public_status($pdo, $resellerId), 'csrf_token' => csrf_token()]);
  }

  json_response(['ok' => false, 'error' => 'Unknown action'], 404);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => safe_public_error($e->getMessage()) ?: '2-step verifikacija trenutno nije dostupna.'], 500);
}
