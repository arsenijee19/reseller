<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_csrf();
$reseller = require_reseller();

$input = read_json_body();
$accountEmail = normalize_email((string)($input['account_email'] ?? ''));
$orderReference = h_string($input['order_reference'] ?? '');
$lockName = '';
$lockAcquired = false;

function release_inventory_lock(PDO $pdo, string $lockName, bool &$lockAcquired): void {
  if (!$lockAcquired || $lockName === '') return;
  $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
  $release->execute([$lockName]);
  $lockAcquired = false;
}

if (!valid_email($accountEmail)) {
  json_response(['ok' => false, 'error' => 'Unesite validan email PlayStation naloga.'], 400);
}

try {
  $pdo = db();
  ensure_security_tables($pdo);
  $resellerId = (int)$reseller['id'];
  require_completed_profile($pdo, $resellerId);

  $profile = reseller_profile($pdo, $resellerId);
  if (!$profile || !valid_email((string)$profile['email'])) {
    json_response(['ok' => false, 'error' => 'Prvo podesite validan email u profilu.'], 428);
  }

  $recipientEmail = normalize_email((string)$profile['email']);
  $apiBase = app_base_url();
  $supplierToken = inventory_supplier_token();
  if ($apiBase === '' || $supplierToken === '') {
    audit_event($pdo, 'reseller', $resellerId, 'verification_code_config_missing', 'failed');
    json_response(['ok' => false, 'error' => 'Verifikacioni kod trenutno nije dostupan. Kontaktirajte PlayWorld podršku.'], 503);
  }

  $recentLimit = $pdo->prepare("
    SELECT COUNT(*)
    FROM inventory_api_requests
    WHERE reseller_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
  ");
  $recentLimit->execute([$resellerId]);
  if ((int)$recentLimit->fetchColumn() >= 10) {
    audit_event($pdo, 'reseller', $resellerId, 'verification_code_rate_limited', 'failed', ['account_hash' => request_fingerprint($accountEmail)]);
    json_response(['ok' => false, 'error' => 'Previše zahteva u kratkom periodu. Pokušajte ponovo kasnije.'], 429);
  }

  $today = app_today();
  $lockName = 'pwrs_2fa_' . $resellerId . '_' . substr(request_fingerprint($accountEmail . '|' . $today), 0, 32);
  $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
  $lockStmt->execute([$lockName]);
  if ((int)$lockStmt->fetchColumn() !== 1) {
    json_response(['ok' => false, 'error' => 'Zahtev se već obrađuje. Sačekajte trenutak i pokušajte ponovo.'], 429);
  }
  $lockAcquired = true;

  $recent = $pdo->prepare("
    SELECT *
    FROM inventory_api_requests
    WHERE reseller_id = ?
      AND account_email = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
    ORDER BY id DESC
    LIMIT 1
  ");
  $recent->execute([$resellerId, $accountEmail]);
  $recentRow = $recent->fetch(PDO::FETCH_ASSOC);
  if ($recentRow) {
    release_inventory_lock($pdo, $lockName, $lockAcquired);
    json_response([
      'ok' => true,
      'duplicate' => true,
      'message' => 'Zahtev je već kreiran. Proverite email pre ponovnog slanja.',
      'account_email' => $accountEmail,
      'recipient_email_masked' => mask_email($recipientEmail),
      'status' => (string)($recentRow['inventory_status'] ?: $recentRow['result']),
      'sent_at' => (string)$recentRow['created_at'],
      'csrf_token' => csrf_token(),
    ]);
  }

  $countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM inventory_api_requests
    WHERE reseller_id = ?
      AND account_email = ?
      AND DATE(created_at) = ?
      AND result IN ('success', 'duplicate', 'inventory_error', 'inventory_502', 'timeout', 'auth_error')
  ");
  $countStmt->execute([$resellerId, $accountEmail, $today]);
  $usedToday = (int)$countStmt->fetchColumn();
  if ($usedToday >= 3) {
    $externalRequestId = 'reseller-limit-' . $resellerId . '-' . substr(request_fingerprint($accountEmail . '|' . $today . '|limit'), 0, 24);
    $fingerprint = request_fingerprint($externalRequestId);
    $insertLimit = $pdo->prepare("
      INSERT IGNORE INTO inventory_api_requests
        (reseller_id, account_email, recipient_email, order_reference, external_request_id, idempotency_fingerprint, result, error_message, ip_address)
      VALUES (?, ?, ?, ?, ?, ?, 'daily_limit', ?, ?)
    ");
    $insertLimit->execute([
      $resellerId,
      $accountEmail,
      $recipientEmail,
      $orderReference,
      $externalRequestId,
      $fingerprint,
      'Daily limit reached for reseller/account/day.',
      client_ip(),
    ]);
    audit_event($pdo, 'reseller', $resellerId, 'verification_code_daily_limit', 'failed', ['account_hash' => request_fingerprint($accountEmail)]);
    release_inventory_lock($pdo, $lockName, $lockAcquired);
    json_response(['ok' => false, 'error' => 'Dnevni limit je dostignut za ovaj PlayStation nalog. Maksimalno su dozvoljena 3 zahteva dnevno.'], 429);
  }

  $slot = $usedToday + 1;
  $externalRequestId = 'reseller-request-' . $resellerId . '-' . $today . '-' . substr(request_fingerprint($accountEmail), 0, 16) . '-' . $slot;
  $idempotencyKey = 'reseller-' . $resellerId . '-2fa-' . $today . '-' . substr(request_fingerprint($accountEmail), 0, 20) . '-' . $slot;
  $fingerprint = request_fingerprint($idempotencyKey);

  $stmt = $pdo->prepare("
    INSERT INTO inventory_api_requests
      (reseller_id, account_email, recipient_email, order_reference, external_request_id, idempotency_fingerprint, result, ip_address, request_sent_at)
    VALUES (?, ?, ?, ?, ?, ?, 'created', ?, NOW())
  ");
  $stmt->execute([$resellerId, $accountEmail, $recipientEmail, $orderReference, $externalRequestId, $fingerprint, client_ip()]);
  $requestDbId = (int)$pdo->lastInsertId();

  $payload = [
    'account_email' => $accountEmail,
    'recipient_email' => $recipientEmail,
    'idempotency_key' => $idempotencyKey,
    'external_request_id' => $externalRequestId,
    'order_id' => $orderReference,
    'requested_by' => 'reseller-' . $resellerId,
  ];

  $url = $apiBase . '/api/supplier/v1/2fa/email-code-requests';
  $response = post_json($url, $payload, 15, [
    'Authorization: Bearer ' . $supplierToken,
    'Idempotency-Key: ' . $idempotencyKey,
  ]);

  $decoded = [];
  if (is_string($response['body']) && $response['body'] !== '') {
    $json = json_decode($response['body'], true);
    if (is_array($json)) $decoded = $json;
  }

  $httpStatus = (int)$response['code'];
  $inventoryStatus = h_string($decoded['status'] ?? '');
  $emailStatus = h_string($decoded['email_status'] ?? '');
  $duplicate = !empty($decoded['duplicate']) ? 1 : 0;
  $inventoryRequestId = h_string($decoded['request_id'] ?? '');
  $message = safe_public_error((string)($decoded['message'] ?? $response['err'] ?? ''));
  $result = 'inventory_error';

  if ($httpStatus >= 200 && $httpStatus < 300 && !empty($decoded['success'])) {
    $result = $duplicate ? 'duplicate' : 'success';
  } elseif (in_array($httpStatus, [401, 403], true)) {
    $result = 'auth_error';
  } elseif ($httpStatus === 502) {
    $result = 'inventory_502';
  } elseif ($httpStatus === 0) {
    $result = 'timeout';
  }

  $update = $pdo->prepare("
    UPDATE inventory_api_requests
    SET inventory_request_id = ?,
        http_status = ?,
        inventory_status = ?,
        email_status = ?,
        duplicate = ?,
        result = ?,
        error_message = ?,
        response_received_at = NOW(),
        duration_ms = ?
    WHERE id = ?
  ");
  $update->execute([
    $inventoryRequestId ?: null,
    $httpStatus ?: null,
    $inventoryStatus ?: null,
    $emailStatus ?: null,
    $duplicate,
    $result,
    $message ?: null,
    (int)($response['duration_ms'] ?? 0),
    $requestDbId,
  ]);

  audit_event($pdo, 'reseller', $resellerId, 'verification_code_request', $result, [
    'account_hash' => request_fingerprint($accountEmail),
    'http_status' => $httpStatus,
    'duplicate' => (bool)$duplicate,
  ]);

  release_inventory_lock($pdo, $lockName, $lockAcquired);

  if ($result === 'success' || $result === 'duplicate') {
    json_response([
      'ok' => true,
      'duplicate' => (bool)$duplicate,
      'message' => $duplicate ? 'Zahtev je već postojao. Proverite email.' : 'Kod je poslat na vaš email.',
      'account_email' => $accountEmail,
      'recipient_email_masked' => mask_email($recipientEmail),
      'status' => $inventoryStatus ?: 'sent',
      'email_status' => $emailStatus ?: 'sent',
      'sent_at' => gmdate('c'),
      'csrf_token' => csrf_token(),
    ]);
  }

  if ($httpStatus === 404) {
    json_response(['ok' => false, 'error' => 'Kod trenutno nije dostupan. Obratite se PlayWorld podršci.'], 404);
  }
  if ($httpStatus === 429) {
    json_response(['ok' => false, 'error' => 'Trenutno nije moguće zatražiti novi kod. Pokušajte ponovo kasnije.'], 429);
  }
  if ($httpStatus === 502) {
    json_response(['ok' => false, 'error' => 'Došlo je do problema prilikom slanja koda. Nemojte odmah ponavljati zahtev. Obratite se PlayWorld podršci ako kod ne stigne.'], 502);
  }
  if (in_array($httpStatus, [401, 403], true)) {
    json_response(['ok' => false, 'error' => 'Verifikacioni kod trenutno nije dostupan zbog tehničkog podešavanja. Kontaktirajte PlayWorld podršku.'], 502);
  }

  json_response(['ok' => false, 'error' => 'Verifikacioni kod trenutno nije dostupan. Kontaktirajte PlayWorld podršku.'], 502);
} catch (Throwable $e) {
  if (isset($pdo)) {
    release_inventory_lock($pdo, $lockName, $lockAcquired);
  }
  json_response(['ok' => false, 'error' => safe_public_error($e->getMessage()) ?: 'Greška pri slanju zahteva za kod.'], 500);
}
