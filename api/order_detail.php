<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Not logged in -> 401 and the page asks for the token first, then opens the order.
$reseller = require_reseller();
$resellerId = (int)$reseller['id'];

$ref = (string)($_GET['ref'] ?? '');
$orderId = $ref !== '' ? order_link_order_id($ref, $resellerId) : (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
  json_response(['ok' => false, 'error' => $ref !== ''
    ? 'Ova porudžbina ne pripada nalogu na koji ste prijavljeni.'
    : 'Porudžbina nije pronađena.', 'not_found' => true], 404);
}

try {
  $pdo = db();
  require_completed_profile($pdo, $resellerId);
  $columns = column_names($pdo, 'orders');
  $optional = [];
  foreach (['reseller_notes', 'reseller_paid', 'reseller_paid_at', 'status', 'canceled_at', 'cancellation_reason', 'buyer_email', 'request_id'] as $column) {
    $optional[] = in_array($column, $columns, true) ? "o.{$column}" : "NULL AS {$column}";
  }
  $stmt = $pdo->prepare('SELECT o.id, o.product_id, o.price_rsd, o.created_at, ' . implode(', ', $optional) . ',
      pp.product_name, pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
    WHERE o.id = ? AND o.reseller_id = ?
    LIMIT 1');
  $stmt->execute([$orderId, $resellerId]);
  $order = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena na ovom nalogu.', 'not_found' => true], 404);
  }

  $canceled = (string)($order['canceled_at'] ?? '') !== '' || in_array(strtolower((string)($order['status'] ?? '')), ['canceled', 'cancelled'], true);

  $record = null;
  try {
    ensure_customer_records_table($pdo);
    $recordStmt = $pdo->prepare('SELECT id, order_id, payload, created_at, updated_at FROM reseller_customer_records WHERE reseller_id = ? AND order_id = ? LIMIT 1');
    $recordStmt->execute([$resellerId, $orderId]);
    $row = $recordStmt->fetch(PDO::FETCH_ASSOC);
    $fields = $row ? customer_record_open((string)$row['payload']) : null;
    if ($row && $fields !== null) {
      $record = ['id' => (int)$row['id'], 'order_id' => $orderId, 'created_at' => (string)$row['created_at'], 'updated_at' => $row['updated_at']];
      foreach (array_keys(CUSTOMER_RECORD_FIELDS) as $field) $record[$field] = (string)($fields[$field] ?? '');
    }
  } catch (Throwable $ignored) {}

  json_response([
    'ok' => true,
    'order' => [
      'id' => (int)$order['id'],
      'product_id' => (string)$order['product_id'],
      'product_name' => (string)($order['product_name'] ?? ''),
      'account_type' => (string)($order['account_type'] ?? ''),
      'price_rsd' => (int)$order['price_rsd'],
      'created_at' => (string)$order['created_at'],
      'reseller_notes' => (string)($order['reseller_notes'] ?? ''),
      'reseller_paid' => (int)($order['reseller_paid'] ?? 0) === 1,
      'reseller_paid_at' => $order['reseller_paid_at'] === null ? null : (string)$order['reseller_paid_at'],
      'delivery_email' => (string)($order['buyer_email'] ?? ''),
      'canceled' => $canceled,
      'canceled_at' => $order['canceled_at'] === null ? null : (string)$order['canceled_at'],
      'cancellation_reason' => (string)($order['cancellation_reason'] ?? ''),
      'link' => order_detail_url($orderId, $resellerId),
    ],
    'record' => $record,
    'csrf_token' => csrf_token(),
  ]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Porudžbina trenutno nije dostupna. Pokušajte ponovo.'], 500);
}
