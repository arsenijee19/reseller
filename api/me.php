<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

start_secure_session();
$reseller = require_reseller();

try {
  $pdo = db();
  $rid = (int)$reseller["id"];

  $stmt = $pdo->prepare("SELECT email, balance_rsd FROM resellers WHERE id=? LIMIT 1");
  $stmt->execute([$rid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo json_encode(["ok"=>false,"error"=>"Reseller not found"]);
    exit;
  }

  echo json_encode([
    "ok" => true,
    "email" => $row["email"],
    "balance_rsd" => (int)$row["balance_rsd"],
    "csrf_token" => csrf_token()
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}
