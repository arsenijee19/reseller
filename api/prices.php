<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");

start_secure_session();

// mora biti ulogovan reseller
if (!isset($_SESSION["reseller_id"])) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Unauthorized"]);
  exit;
}

try {
  $pdo = db();

  $whereActive = has_column($pdo, 'product_prices', 'status') ? "WHERE status = 'active'" : "";
  $stmt = $pdo->query("
    SELECT product_id, product_name, account_type, price, currency
    FROM product_prices
    {$whereActive}
    ORDER BY product_name, account_type
  ");

  $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(["ok"=>true, "prices"=>$prices]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
}
