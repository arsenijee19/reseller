<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$reseller = require_reseller();
$resellerId = (int)$reseller['id'];

function customer_record_row(array $row): ?array {
  $fields = customer_record_open((string)$row['payload']);
  if ($fields === null) return null;
  $out = ['id' => (int)$row['id'], 'order_id' => $row['order_id'] === null ? null : (int)$row['order_id']];
  foreach (array_keys(CUSTOMER_RECORD_FIELDS) as $field) $out[$field] = (string)($fields[$field] ?? '');
  $out['created_at'] = (string)$row['created_at'];
  $out['updated_at'] = $row['updated_at'] === null ? null : (string)$row['updated_at'];
  return $out;
}

function customer_record_fetch(PDO $pdo, int $resellerId, int $id): ?array {
  $stmt = $pdo->prepare('SELECT id, order_id, payload, created_at, updated_at FROM reseller_customer_records WHERE id = ? AND reseller_id = ? LIMIT 1');
  $stmt->execute([$id, $resellerId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ? customer_record_row($row) : null;
}

try {
  $pdo = db();
  ensure_customer_records_table($pdo);

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id, order_id, payload, created_at, updated_at FROM reseller_customer_records WHERE reseller_id = ? ORDER BY created_at DESC, id DESC LIMIT 2000');
    $stmt->execute([$resellerId]);
    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $record = customer_record_row($row);
      if ($record !== null) $records[] = $record;
    }
    json_response(['ok' => true, 'records' => $records, 'csrf_token' => csrf_token()]);
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }
  require_same_origin();
  require_json_content_type();
  require_csrf();
  $input = read_json_body();
  $action = (string)($input['action'] ?? 'save');

  if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM reseller_customer_records WHERE id = ? AND reseller_id = ?');
    $stmt->execute([$id, $resellerId]);
    if ($stmt->rowCount() !== 1) json_response(['ok' => false, 'error' => 'Evidencija nije pronađena.'], 404);
    json_response(['ok' => true, 'deleted_id' => $id]);
  }

  if ($action !== 'save') json_response(['ok' => false, 'error' => 'Nepoznata akcija.'], 400);

  try {
    $fields = customer_record_clean($input);
  } catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
  }
  $id = (int)($input['id'] ?? 0);
  $orderId = (int)($input['order_id'] ?? 0);
  $isEmpty = implode('', $fields) === '';

  if ($id <= 0 && $orderId > 0) {
    // One record per portal order: find it, or check the order really belongs to this reseller.
    $stmt = $pdo->prepare('SELECT id FROM reseller_customer_records WHERE reseller_id = ? AND order_id = ? LIMIT 1');
    $stmt->execute([$resellerId, $orderId]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id <= 0) {
      $own = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND reseller_id = ? LIMIT 1');
      $own->execute([$orderId, $resellerId]);
      if (!$own->fetchColumn()) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
    }
  }

  if ($id > 0) {
    $existing = customer_record_fetch($pdo, $resellerId, $id);
    if ($existing === null) json_response(['ok' => false, 'error' => 'Evidencija nije pronađena.'], 404);
    if ($isEmpty) {
      // Emptying every field removes the record (for an order this just clears the buyer data).
      $pdo->prepare('DELETE FROM reseller_customer_records WHERE id = ? AND reseller_id = ?')->execute([$id, $resellerId]);
      json_response(['ok' => true, 'deleted_id' => $id, 'record' => null]);
    }
    if ($existing['order_id'] === null && $fields['title'] === '' && $fields['account_email'] === '') {
      json_response(['ok' => false, 'error' => 'Upišite igru ili email naloga.'], 400);
    }
    $pdo->prepare('UPDATE reseller_customer_records SET payload = ?, updated_at = NOW() WHERE id = ? AND reseller_id = ?')
      ->execute([customer_record_seal($fields), $id, $resellerId]);
    json_response(['ok' => true, 'record' => customer_record_fetch($pdo, $resellerId, $id)]);
  }

  if ($isEmpty) json_response(['ok' => false, 'error' => 'Upišite bar jedan podatak.'], 400);
  if ($orderId <= 0 && $fields['title'] === '' && $fields['account_email'] === '') {
    json_response(['ok' => false, 'error' => 'Upišite igru ili email naloga.'], 400);
  }
  $count = $pdo->prepare('SELECT COUNT(*) FROM reseller_customer_records WHERE reseller_id = ?');
  $count->execute([$resellerId]);
  if ((int)$count->fetchColumn() >= 5000) json_response(['ok' => false, 'error' => 'Dostignut je maksimalan broj evidencija.'], 409);

  $pdo->prepare('INSERT INTO reseller_customer_records (reseller_id, order_id, payload) VALUES (?, ?, ?)')
    ->execute([$resellerId, $orderId > 0 ? $orderId : null, customer_record_seal($fields)]);
  json_response(['ok' => true, 'record' => customer_record_fetch($pdo, $resellerId, (int)$pdo->lastInsertId())]);
} catch (Throwable $e) {
  error_log('customer_records_failed class=' . get_class($e));
  json_response(['ok' => false, 'error' => 'Evidencija trenutno nije sačuvana. Pokušajte ponovo.'], 500);
}
