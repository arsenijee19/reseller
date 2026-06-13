<?php
declare(strict_types=1);

require __DIR__ . "/db.php";
header("Content-Type: application/json; charset=utf-8");

session_start();

if (!isset($_SESSION["reseller_id"], $_SESSION["reseller_email"])) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Unauthorized"]);
  exit;
}

try {
  $pdo = db();
  $rid = (int)$_SESSION["reseller_id"];

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
    "balance_rsd" => (int)$row["balance_rsd"]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}