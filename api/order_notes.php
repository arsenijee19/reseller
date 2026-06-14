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
$notes = trim((string)($input['notes'] ?? ''));

if ($orderId <= 0) {
  json_response(['ok' => false, 'error' => 'Neispravna porudžbina.'], 400);
}

$notesLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
if ($notesLength > 2000) {
  json_response(['ok' => false, 'error' => 'Notes može imati najviše 2000 karaktera.'], 400);
}

try {
  $pdo = db();

  if (!has_column($pdo, 'orders', 'reseller_notes')) {
    json_response(['ok' => false, 'error' => 'Notes kolona nije dostupna. Pokreni SQL migraciju za reseller notes.'], 500);
  }

  $sets = ['reseller_notes = ?'];
  if (has_column($pdo, 'orders', 'updated_at')) {
    $sets[] = 'updated_at = NOW()';
  }

  $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND reseller_id = ?');
  $stmt->execute([$notes, $orderId, (int)$reseller['id']]);

  if ($stmt->rowCount() < 1) {
    $check = $pdo->prepare('SELECT id FROM orders WHERE id = ? AND reseller_id = ? LIMIT 1');
    $check->execute([$orderId, (int)$reseller['id']]);
    if (!$check->fetchColumn()) {
      json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
    }
  }

  json_response(['ok' => true, 'notes' => $notes]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri čuvanju notes-a.'], 500);
}
