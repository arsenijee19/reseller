<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();

  $stmt = $pdo->query("
    SELECT
      product_id AS id,
      CONCAT(product_name, ' - ', account_type) AS name
    FROM product_prices
    ORDER BY product_name ASC, account_type ASC
  ");

  echo json_encode([
    'ok' => true,
    'products' => $stmt->fetchAll(),
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);

  echo json_encode([
    'ok' => false,
    'error' => 'Greška pri učitavanju proizvoda.',
  ], JSON_UNESCAPED_UNICODE);
}