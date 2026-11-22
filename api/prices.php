<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

start_secure_session();

// mora biti ulogovan reseller (aktivan nalog, nepromenjen token)
$reseller = require_reseller();

try {
  $pdo = db();
  ensure_security_tables($pdo);
  require_completed_profile($pdo, (int)$reseller["id"]);
  $profile = reseller_profile($pdo, (int)$reseller["id"]);
  $discountPercent = normalized_discount_percent($profile['discount_percent'] ?? 0);

  $whereActive = has_column($pdo, 'product_prices', 'status') ? "WHERE status = 'active'" : "";
  $imageSelect = ensure_product_image_column($pdo) ? 'image_file' : 'NULL AS image_file';
  $stmt = $pdo->query("
    SELECT product_id, product_name, account_type, price, currency, {$imageSelect}
    FROM product_prices
    {$whereActive}
    ORDER BY product_name, account_type
  ");

  $prices = array_map(static function (array $row) use ($discountPercent): array {
    $basePrice = (int)$row['price'];
    $row['base_price'] = $basePrice;
    $row['price'] = discounted_price($basePrice, $discountPercent);
    $row['discount_percent'] = $discountPercent;
    $row['image'] = product_image_url($row['image_file'] ?? null);
    unset($row['image_file']);
    return $row;
  }, $stmt->fetchAll(PDO::FETCH_ASSOC));

  echo json_encode(["ok"=>true, "prices"=>$prices, "discount_percent"=>$discountPercent]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "error"=>"Greška pri učitavanju cenovnika."]);
}
