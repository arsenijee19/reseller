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
$paid = !empty($input['paid']) ? 1 : 0;

if ($orderId <= 0) {
  json_response(['ok' => false, 'error' => 'Neispravna porudžbina.'], 400);
}

try {
  $pdo = db();

  if (!has_column($pdo, 'orders', 'reseller_paid')) {
    json_response(['ok' => false, 'error' => 'Plaćeno kolona nije dostupna. Pokreni SQL migraciju za reseller notes.'], 500);
  }

  $sets = ['reseller_paid = ?'];
  $params = [$paid];

  if (has_column($pdo, 'orders', 'reseller_paid_at')) {
    $sets[] = 'reseller_paid_at = ' . ($paid ? 'NOW()' : 'NULL');
  }
  if (has_column($pdo, 'orders', 'updated_at')) {
    $sets[] = 'updated_at = NOW()';
  }

  $params[] = $orderId;
  $params[] = (int)$reseller['id'];

  $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND reseller_id = ?');
  $stmt->execute($params);

  if ($stmt->rowCount() < 1) {
    $check = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND reseller_id = ? LIMIT 1');
    $check->execute([$orderId, (int)$reseller['id']]);
    if (!$check->fetchColumn()) {
      json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
    }
  }

  json_response(['ok' => true, 'paid' => (bool)$paid]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri čuvanju statusa plaćanja.'], 500);
}
