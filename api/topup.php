<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_same_origin();
require_json_content_type();

require_admin();
require_csrf();
require_recent_admin_step_up();

$input = read_json_body();
$email = h_string($input['email'] ?? '');
$amount = (int)($input['amount'] ?? 0);

if (!valid_email($email) || $amount === 0) {
  json_response(['ok' => false, 'error' => 'Missing or invalid email/amount'], 400);
}

try {
  $pdo = db();
  ensure_security_tables($pdo);
  $pdo->beginTransaction();

  $st = $pdo->prepare('SELECT id FROM resellers WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $rid = (int)$st->fetchColumn();

  if (!$rid) {
    throw new RuntimeException('Reseller nije pronađen.');
  }
  $wallet = apply_wallet_transaction($pdo, $rid, $amount, 'ADMIN_TOPUP', 'Admin topup', [
    'source' => 'panel',
    'admin_id' => (int)($_SESSION['admin_id'] ?? 0),
  ]);
  $newBal = (int)$wallet['balance_after_rsd'];

  $pdo->commit();
  json_response(['ok' => true, 'new_balance' => $newBal]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_response(['ok' => false, 'error' => 'Admin topup trenutno nije mogao da se obradi.'], 500);
}
