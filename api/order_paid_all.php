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

try {
  $pdo = db();

  if (!has_column($pdo, 'orders', 'reseller_paid')) {
    json_response(['ok' => false, 'error' => 'Plaćeno kolona nije dostupna. Pokreni SQL migraciju za reseller notes.'], 500);
  }

  $sets = ['reseller_paid = 1'];
  if (has_column($pdo, 'orders', 'reseller_paid_at')) {
    $sets[] = 'reseller_paid_at = COALESCE(reseller_paid_at, NOW())';
  }
  if (has_column($pdo, 'orders', 'updated_at')) {
    $sets[] = 'updated_at = NOW()';
  }

  $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE reseller_id = ? AND COALESCE(reseller_paid, 0) <> 1');
  $stmt->execute([(int)$reseller['id']]);

  json_response(['ok' => true, 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri označavanju porudžbina kao plaćenih.'], 500);
}
