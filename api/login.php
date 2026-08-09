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

try {
  $pdo = db();
  ensure_security_tables($pdo);
  enforce_rate_limit(
    $pdo,
    'reseller_login',
    client_ip(),
    12,
    900,
    'Previše pokušaja prijave. Sačekajte nekoliko minuta i pokušajte ponovo.'
  );

  // Tražimo aktivne resellere (token se proverava preko password_verify)
  $stmt = $pdo->prepare("SELECT id, email, token_hash, status FROM resellers WHERE status='active'");
  $stmt->execute();
  $resellers = $stmt->fetchAll();

  $found = null;
  foreach ($resellers as $r) {
    $hash = (string)($r["token_hash"] ?? "");
    if ($hash !== "" && password_verify($token, $hash)) {
      $found = $r; break;
    }
  }

  if (!$found) {
    record_login_attempt($pdo, 'reseller_login', client_ip(), false);
    audit_event($pdo, 'reseller', null, 'login_failed', 'failed', ['reason' => 'invalid_token']);
    http_response_code(401);
    echo json_encode(["ok"=>false,"error"=>"Pogrešan token."]); exit;
  }

  start_secure_session();
  session_regenerate_id(true);
  $_SESSION["reseller_id"] = (int)$found["id"];
  $_SESSION["reseller_email"] = $found["email"];
  $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
  record_login_attempt($pdo, 'reseller_login', client_ip(), true);
  audit_event($pdo, 'reseller', (int)$found['id'], 'login_success', 'success');

  echo json_encode(["ok"=>true, "csrf_token"=>csrf_token()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => "Server trenutno ne može da proveri login. " . public_error_detail($e)
  ], JSON_UNESCAPED_UNICODE);
}
