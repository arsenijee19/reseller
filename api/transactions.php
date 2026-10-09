<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

start_secure_session();
$reseller = require_reseller();

try {
  $pdo = db();
  $resellerId = (int)$reseller['id'];
  require_completed_profile($pdo, $resellerId);

  $hasRelatedOrder = has_column($pdo, 'wallet_transactions', 'related_order_id');
  $relatedOrder = $hasRelatedOrder ? 'wt.related_order_id' : 'NULL AS related_order_id';
  $createdAt = has_column($pdo, 'wallet_transactions', 'created_at') ? 'wt.created_at' : 'NULL AS created_at';
  $hasOrderNotes = $hasRelatedOrder && has_column($pdo, 'orders', 'reseller_notes');
  $orderNote = $hasOrderNotes ? 'o.reseller_notes AS order_note' : "'' AS order_note";
  $orderJoin = $hasRelatedOrder
    ? 'LEFT JOIN orders o ON o.id = wt.related_order_id AND o.reseller_id = wt.reseller_id'
    : '';

  $stmt = $pdo->prepare("SELECT wt.id, wt.type, wt.amount_rsd, wt.description, {$relatedOrder}, {$createdAt}, {$orderNote}
    FROM wallet_transactions wt
    {$orderJoin}
    WHERE wt.reseller_id = ?
    ORDER BY wt.id DESC
    LIMIT 500");
  $stmt->execute([$resellerId]);

  json_response(['ok' => true, 'transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri učitavanju transakcija.'], 500);
}
