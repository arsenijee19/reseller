<?php
declare(strict_types=1);
require __DIR__ . "/bootstrap.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok"=>false,"error"=>"Method not allowed"]); exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$token = trim((string)($input["token"] ?? ""));

if ($token === "") {
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"Missing token"]); exit;
}

$pdo = db();

// Tražimo aktivne resellere (token se proverava preko password_verify)
$stmt = $pdo->prepare("SELECT id, email, token_hash, status FROM resellers WHERE status='active'");
$stmt->execute();
$resellers = $stmt->fetchAll();

$found = null;
foreach ($resellers as $r) {
  if (password_verify($token, $r["token_hash"])) {
    $found = $r; break;
  }
}

if (!$found) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"error"=>"Invalid token"]); exit;
}

start_secure_session();
session_regenerate_id(true);
$_SESSION["reseller_id"] = (int)$found["id"];
$_SESSION["reseller_email"] = $found["email"];
$_SESSION["csrf_token"] = bin2hex(random_bytes(32));

echo json_encode(["ok"=>true, "csrf_token"=>csrf_token()]);
