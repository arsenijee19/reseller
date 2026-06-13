<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";
header("Content-Type: application/json; charset=utf-8");
start_secure_session();

$reseller = require_reseller();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok"=>false,"error"=>"Method not allowed"]); exit;
}

require_csrf();

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$product_id = trim((string)($input["product_id"] ?? ""));
$customer_email = trim((string)($input["customer_email"] ?? ""));

if ($product_id === "" || $customer_email === "") {
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"Missing product_id or customer_email"]); exit;
}

$reseller_id = (int)$reseller["id"];
$reseller_email = (string)$reseller["email"];

$N8N_WEBHOOK = "https://automation.psigre.rs/webhook/reseller-delivery";

function post_json(string $url, array $payload, int $timeoutSeconds = 12): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
    CURLOPT_TIMEOUT => $timeoutSeconds,
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return ["ok" => ($err === "" && $code >= 200 && $code < 300), "code"=>$code, "err"=>$err, "body"=>$body];
}

$pdo = db();

try {
  $pdo->beginTransaction();

  // 1) Cena proizvoda
  $whereActive = has_column($pdo, 'product_prices', 'status') ? " AND status='active'" : "";
  $st = $pdo->prepare("SELECT price, currency, product_name, account_type FROM product_prices WHERE product_id=?{$whereActive} LIMIT 1");
  $st->execute([$product_id]);
  $p = $st->fetch(PDO::FETCH_ASSOC);

  if (!$p) {
    throw new Exception("Price not found for product_id: " . $product_id);
  }
  if (strtoupper((string)$p["currency"]) !== "RSD") {
    throw new Exception("Currency must be RSD for wallet.");
  }

  $price = (int)$p["price"];
  $desc  = "Order: ".$p["product_name"]." / ".$p["account_type"];

  // 2) request_id
  $request_id = bin2hex(random_bytes(16));

  // 3) Upis order
  $ins = $pdo->prepare("INSERT INTO orders (request_id, reseller_id, reseller_email, product_id, buyer_email, price_rsd, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())");
  $ins->execute([$request_id, $reseller_id, $reseller_email, $product_id, $customer_email, $price]);

  $orderDbId = (int)$pdo->lastInsertId();

  // 4) Wallet transakcija
  $wt = $pdo->prepare("INSERT INTO wallet_transactions (reseller_id, type, amount_rsd, description, related_order_id)
                       VALUES (?, 'ORDER', ?, ?, ?)");
  $wt->execute([$reseller_id, -$price, $desc, $orderDbId]);

  // 5) Update balansa
  $up = $pdo->prepare("UPDATE resellers SET balance_rsd = balance_rsd - ? WHERE id=?");
  $up->execute([$price, $reseller_id]);

  // 6) Novi balans
  $b = $pdo->prepare("SELECT balance_rsd FROM resellers WHERE id=?");
  $b->execute([$reseller_id]);
  $newBal = (int)$b->fetchColumn();

  $pdo->commit();

  // ===============================
  // SLANJE EMAIL NOTIFIKACIJE
  // ===============================

  $to = "arsenijee19@gmail.com, sold@psigre.rs";
  $subject = "Nova porudzbina - " . $p["product_name"];

  $message = "Nova porudzbina:\n\n";
  $message .= "Request ID: " . $request_id . "\n";
  $message .= "Proizvod: " . $p["product_name"] . "\n";
  $message .= "Tip naloga: " . $p["account_type"] . "\n";
  $message .= "Cena (RSD): " . $price . "\n\n";

  $message .= "Podaci o reselleru:\n";
  $message .= "Reseller ID: " . $reseller_id . "\n";
  $message .= "Reseller Email: " . $reseller_email . "\n\n";

  $message .= "Kupac email: " . $customer_email . "\n";
  $message .= "Vreme: " . gmdate("Y-m-d H:i:s") . " UTC\n";

  $headers = "From: no-reply@psigre.rs\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

  @mail($to, $subject, $message, $headers);

  // ===============================
  // POZIV N8N
  // ===============================

  $payload = [
    "request_id" => $request_id,
    "reseller_email" => $reseller_email,
    "product_id" => $product_id,
    "customer_email" => $customer_email,
    "ts" => gmdate("c")
  ];

  $n8n = post_json($N8N_WEBHOOK, $payload);

  echo json_encode([
    "ok"=>true,
    "request_id"=>$request_id,
    "charged_rsd"=>$price,
    "balance_rsd"=>$newBal,
    "n8n_ok"=>$n8n["ok"],
    "n8n_code"=>$n8n["code"]
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}
