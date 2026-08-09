<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$reseller = require_reseller();
$pdo = db();
$resellerId = (int)$reseller['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $profile = reseller_profile($pdo, $resellerId);
  if (!$profile) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen.'], 404);
  }

  json_response([
    'ok' => true,
    'email' => (string)$profile['email'],
    'phone' => (string)($profile['phone'] ?? ''),
    'profile_completed' => !has_column($pdo, 'resellers', 'profile_completed_at') || (string)($profile['profile_completed_at'] ?? '') !== '',
    'profile_completed_at' => (string)($profile['profile_completed_at'] ?? ''),
    'credential_changed_at' => (string)($profile['credential_changed_at'] ?? ''),
    'csrf_token' => csrf_token(),
  ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_csrf();

$input = read_json_body();
$email = normalize_email((string)($input['email'] ?? ''));
$phone = normalize_phone((string)($input['phone'] ?? ''));
$currentToken = (string)($input['current_token'] ?? '');
$newToken = (string)($input['new_token'] ?? '');
$confirmToken = (string)($input['confirm_token'] ?? '');
$completeOnboarding = !empty($input['complete_onboarding']);

if (!valid_email($email)) {
  json_response(['ok' => false, 'error' => 'Unesite validnu email adresu.'], 400);
}

if (!valid_phone($phone)) {
  json_response(['ok' => false, 'error' => 'Unesite validan broj telefona u međunarodnom formatu.'], 400);
}

try {
  $profile = reseller_profile($pdo, $resellerId);
  if (!$profile) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen.'], 404);
  }

  if (!has_column($pdo, 'resellers', 'phone') || !has_column($pdo, 'resellers', 'profile_completed_at')) {
    json_response(['ok' => false, 'error' => 'Profile kolone nisu dostupne. Pokrenite SQL migraciju za account security.'], 500);
  }

  $tokenChangeRequested = $newToken !== '' || $confirmToken !== '' || $currentToken !== '';
  if ($tokenChangeRequested) {
    if ($currentToken === '') {
      json_response(['ok' => false, 'error' => 'Unesite trenutni token/šifru za promenu pristupa.'], 400);
    }
    if ($newToken !== $confirmToken) {
      json_response(['ok' => false, 'error' => 'Nova šifra/token i potvrda se ne poklapaju.'], 400);
    }
    if (strlen($newToken) < 12) {
      json_response(['ok' => false, 'error' => 'Nova šifra/token mora imati najmanje 12 karaktera.'], 400);
    }
    $lowerToken = strtolower($newToken);
    if (
      in_array($lowerToken, ['password', '123456789012', 'playworld123', 'reseller1234'], true) ||
      strpos($lowerToken, strtolower($email)) !== false ||
      strpos(preg_replace('/\D+/', '', $newToken), preg_replace('/\D+/', '', $phone)) !== false
    ) {
      json_response(['ok' => false, 'error' => 'Nova šifra/token je previše laka za pogoditi. Izaberite jaču vrednost.'], 400);
    }

    $stmt = $pdo->prepare('SELECT token_hash FROM resellers WHERE id = ? AND status = ? LIMIT 1');
    $stmt->execute([$resellerId, 'active']);
    $hash = (string)($stmt->fetchColumn() ?: '');
    if ($hash === '' || !password_verify($currentToken, $hash)) {
      audit_event($pdo, 'reseller', $resellerId, 'credential_change_failed', 'failed', ['reason' => 'bad_current_token']);
      json_response(['ok' => false, 'error' => 'Trenutni token/šifra nije tačan.'], 401);
    }
  }

  $pdo->beginTransaction();

  $fields = ['email = ?', 'phone = ?'];
  $params = [$email, $phone];

  if ($completeOnboarding || (string)($profile['profile_completed_at'] ?? '') === '') {
    $fields[] = 'profile_completed_at = COALESCE(profile_completed_at, NOW())';
  }
  if ($tokenChangeRequested) {
    $fields[] = 'token_hash = ?';
    $params[] = password_hash($newToken, PASSWORD_DEFAULT);
    if (has_column($pdo, 'resellers', 'credential_changed_at')) {
      $fields[] = 'credential_changed_at = NOW()';
    }
  }
  if (has_column($pdo, 'resellers', 'updated_at')) {
    $fields[] = 'updated_at = NOW()';
  }

  $params[] = $resellerId;
  $update = $pdo->prepare('UPDATE resellers SET ' . implode(', ', $fields) . ' WHERE id = ?');
  $update->execute($params);

  $_SESSION['reseller_email'] = $email;

  audit_event($pdo, 'reseller', $resellerId, $completeOnboarding ? 'profile_completed' : 'profile_updated', 'success', [
    'email_changed' => $email !== normalize_email((string)$profile['email']),
    'phone_changed' => $phone !== (string)($profile['phone'] ?? ''),
  ]);
  if ($tokenChangeRequested) {
    audit_event($pdo, 'reseller', $resellerId, 'credential_changed', 'success');
  }

  $pdo->commit();

  json_response([
    'ok' => true,
    'email' => $email,
    'phone' => $phone,
    'profile_completed' => true,
    'csrf_token' => csrf_token(),
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => safe_public_error($e->getMessage()) ?: 'Greška pri čuvanju profila.'], 500);
}
