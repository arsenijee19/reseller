<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_admin();
require_csrf();

$input = read_json_body();
$email = h_string($input['email'] ?? '');
$amount = (int)($input['amount'] ?? 0);

if (!valid_email($email) || $amount === 0) {
  json_response(['ok' => false, 'error' => 'Missing or invalid email/amount'], 400);
}

try {
  $pdo = db();
  $pdo->beginTransaction();

  $st = $pdo->prepare('SELECT id FROM resellers WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $rid = (int)$st->fetchColumn();

  if (!$rid) {
    throw new RuntimeException('Reseller nije pronađen.');
  }

  $pdo->prepare('UPDATE resellers SET balance_rsd = balance_rsd + ? WHERE id = ?')
      ->execute([$amount, $rid]);

  $pdo->prepare("INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description)
                 VALUES (?, 'ADMIN_TOPUP', ?, ?)")
      ->execute([$rid, $amount, 'Admin topup']);

  $newBalStmt = $pdo->prepare('SELECT balance_rsd FROM resellers WHERE id = ?');
  $newBalStmt->execute([$rid]);
  $newBal = (int)$newBalStmt->fetchColumn();

  $pdo->commit();
  json_response(['ok' => true, 'new_balance' => $newBal]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
