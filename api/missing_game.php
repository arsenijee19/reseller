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
$orderId = (int)($input['order_id'] ?? 0);

if ($orderId <= 0) {
  json_response(['ok' => false, 'error' => 'Izaberite validnu porudžbinu.'], 400);
}

try {
  $pdo = db();
  ensure_security_tables($pdo);
  $resellerId = (int)$reseller['id'];
  require_completed_profile($pdo, $resellerId);

  $profile = reseller_profile($pdo, $resellerId);
  if (!$profile) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen.'], 404);
  }

  $stmt = $pdo->prepare("
    SELECT o.*, pp.product_name, pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
    WHERE o.id = ? AND o.reseller_id = ?
    LIMIT 1
  ");
  $stmt->execute([$orderId, $resellerId]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    audit_event($pdo, 'reseller', $resellerId, 'missing_game_report_forbidden', 'failed', ['order_id' => $orderId]);
    json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
  }

  $pdo->beginTransaction();
  $insert = $pdo->prepare("
    INSERT IGNORE INTO missing_game_reports (reseller_id, order_id, status, notification_status)
    VALUES (?, ?, 'open', 'pending')
  ");
  $insert->execute([$resellerId, $orderId]);

  $reportStmt = $pdo->prepare('SELECT * FROM missing_game_reports WHERE order_id = ? LIMIT 1');
  $reportStmt->execute([$orderId]);
  $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
  $createdNow = $insert->rowCount() > 0;
  $pdo->commit();

  if (!$createdNow) {
    json_response([
      'ok' => true,
      'duplicate' => true,
      'message' => 'Prijava je već poslata za ovu porudžbinu.',
      'status' => (string)($report['status'] ?? 'open'),
      'csrf_token' => csrf_token(),
    ]);
  }

  $payload = [
    'event' => 'reseller_missing_game',
    'report_id' => (int)$report['id'],
    'order_id' => (int)$order['id'],
    'request_id' => (string)($order['request_id'] ?? ''),
    'reseller_id' => $resellerId,
    'reseller_email' => (string)$profile['email'],
    'reseller_phone' => (string)($profile['phone'] ?? ''),
    'product_id' => (string)$order['product_id'],
    'product_name' => (string)($order['product_name'] ?: $order['product_id']),
    'account_type' => (string)($order['account_type'] ?? ''),
    'order_status' => (string)($order['status'] ?? ''),
    'created_at' => (string)$order['created_at'],
    'reported_at' => gmdate('c'),
  ];

  $webhookUrl = (string)config_value('integrations.n8n_webhook', '');
  $response = $webhookUrl !== ''
    ? post_json($webhookUrl, $payload, 12)
    : ['ok' => false, 'code' => 0, 'err' => 'Webhook not configured', 'body' => null, 'duration_ms' => 0];

  $messageId = null;
  if (is_string($response['body'] ?? null) && $response['body'] !== '') {
    $decoded = json_decode($response['body'], true);
    if (is_array($decoded)) {
      $messageId = h_string($decoded['message_id'] ?? $decoded['telegram_message_id'] ?? '');
    }
  }

  $update = $pdo->prepare("
    UPDATE missing_game_reports
    SET notification_status = ?,
        notification_message_id = ?,
        notification_error = ?,
        updated_at = NOW()
    WHERE id = ?
  ");
  $update->execute([
    !empty($response['ok']) ? 'sent' : 'failed',
    $messageId ?: null,
    !empty($response['ok']) ? null : safe_public_error((string)($response['err'] ?? 'Webhook failed')),
    (int)$report['id'],
  ]);

  audit_event($pdo, 'reseller', $resellerId, 'missing_game_report', !empty($response['ok']) ? 'success' : 'notification_failed', [
    'order_id' => $orderId,
    'notification_status' => !empty($response['ok']) ? 'sent' : 'failed',
  ]);

  json_response([
    'ok' => true,
    'duplicate' => false,
    'message' => 'Prijava je poslata PlayWorld timu.',
    'notification_sent' => !empty($response['ok']),
    'csrf_token' => csrf_token(),
  ]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => 'Greška pri slanju prijave.'], 500);
}
