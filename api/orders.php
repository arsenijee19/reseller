<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");

start_secure_session();

if (!isset($_SESSION["reseller_id"])) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Niste ulogovani. Refrešujte stranicu i ulogujte se ponovo."]);
  exit;
}

try {
  $pdo = db();
  $resellerId = (int)$_SESSION["reseller_id"];
  $notesSelect = has_column($pdo, 'orders', 'reseller_notes') ? 'o.reseller_notes,' : "'' AS reseller_notes,";
  $paidSelect = has_column($pdo, 'orders', 'reseller_paid') ? 'o.reseller_paid,' : '0 AS reseller_paid,';
  $paidAtSelect = has_column($pdo, 'orders', 'reseller_paid_at') ? 'o.reseller_paid_at,' : 'NULL AS reseller_paid_at,';

  $stmt = $pdo->prepare("
    SELECT
      o.id,
      o.product_id,
      o.price_rsd,
      o.created_at,
      {$notesSelect}
      {$paidSelect}
      {$paidAtSelect}
      pp.product_name,
      pp.account_type
    FROM orders o
    LEFT JOIN product_prices pp ON pp.product_id = o.product_id
    WHERE o.reseller_id = ?
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 10
  ");
  $stmt->execute([$resellerId]);

  $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(["ok"=>true, "orders"=>$orders]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
}
