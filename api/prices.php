<?php
declare(strict_types=1);

require __DIR__ . "/db.php";

header("Content-Type: application/json; charset=utf-8");

session_start();

// mora biti ulogovan reseller
if (!isset($_SESSION["reseller_id"])) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Unauthorized"]);
  exit;
}

try {
  $pdo = db();

  $stmt = $pdo->query("
    SELECT product_id, product_name, account_type, price, currency
    FROM product_prices
    ORDER BY product_name, account_type
  ");

  $prices = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(["ok"=>true, "prices"=>$prices]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>$e->getMessage()]);
}