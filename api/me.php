<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");

start_secure_session();
$reseller = require_reseller();

try {
  $pdo = db();
  ensure_security_tables($pdo);
  $rid = (int)$reseller["id"];

  $row = reseller_profile($pdo, $rid);

  if (!$row) {
    http_response_code(404);
    echo json_encode(["ok"=>false,"error"=>"Nalog nije pronađen. Refrešujte stranicu i ulogujte se ponovo."]);
    exit;
  }

  echo json_encode([
    "ok" => true,
    "display_name" => (string)($row["display_name"] ?? ""),
    "email" => $row["email"],
    "phone" => (string)($row["phone"] ?? ""),
    "profile_completed" => profile_is_complete($pdo, $rid),
    "profile_completed_at" => (string)($row["profile_completed_at"] ?? ""),
    "credential_changed_at" => (string)($row["credential_changed_at"] ?? ""),
    "security_2fa_reminded_at" => (string)($row["security_2fa_reminded_at"] ?? ""),
    "balance_rsd" => (int)$row["balance_rsd"],
    "csrf_token" => csrf_token()
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>"Greška pri učitavanju profila."]);
}
