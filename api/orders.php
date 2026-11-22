<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");

start_secure_session();

$reseller = require_reseller();

try {
  $pdo = db();
  $resellerId = (int)$reseller["id"];
  require_completed_profile($pdo, $resellerId);
  $notesSelect = has_column($pdo, 'orders', 'reseller_notes') ? 'o.reseller_notes,' : "'' AS reseller_notes,";
  $paidSelect = has_column($pdo, 'orders', 'reseller_paid') ? 'o.reseller_paid,' : '0 AS reseller_paid,';
  $paidAtSelect = has_column($pdo, 'orders', 'reseller_paid_at') ? 'o.reseller_paid_at,' : 'NULL AS reseller_paid_at,';
  $canceledSelect = has_column($pdo, 'orders', 'canceled_at') ? 'o.canceled_at,' : 'NULL AS canceled_at,';
  $statusSelect = has_column($pdo, 'orders', 'status') ? 'o.status AS order_status,' : "'' AS order_status,";
  $allOrders = h_string($_GET['all'] ?? '') === '1';

  $sql = "
    SELECT
      o.id,
      o.product_id,
      o.price_rsd,
      o.created_at,
      {$notesSelect}
      {$paidSelect}
      {$paidAtSelect}
      {$canceledSelect}
      {$statusSelect}
      " . (ensure_product_image_column($pdo) ? 'pp.image_file,' : 'NULL AS image_file,') . "
      " . (ensure_product_period_column($pdo) ? 'pp.subscription_months,' : 'NULL AS subscription_months,') . "
      pp.product_name,
      pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
    WHERE o.reseller_id = ?
    ORDER BY o.created_at DESC, o.id DESC
  ";
  if (!$allOrders) $sql .= ' LIMIT 10';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$resellerId]);

  $orders = array_map(static function (array $order): array {
    $order['image'] = product_image_url($order['image_file'] ?? null);
    unset($order['image_file']);
    return $order;
  }, $stmt->fetchAll(PDO::FETCH_ASSOC));

  echo json_encode(["ok"=>true, "orders"=>$orders]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>"Greška pri učitavanju porudžbina."]);
}
