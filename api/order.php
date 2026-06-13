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

function post_json(string $url, array $payload, int $timeoutSeconds = 12, array $extraHeaders = []): array {
  $ch = curl_init($url);
  $headers = array_merge(["Content-Type: application/json"], $extraHeaders);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
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

function update_order_delivery_state(PDO $pdo, int $orderId, string $status, array $payload = [], string $notes = ''): void {
  $sets = [];
  $params = [];

  if (has_column($pdo, 'orders', 'status')) {
    $sets[] = 'status = ?';
    $params[] = $status;
  }
  if (has_column($pdo, 'orders', 'delivery_payload')) {
    $sets[] = 'delivery_payload = ?';
    $params[] = json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
  if ($notes !== '' && has_column($pdo, 'orders', 'admin_notes')) {
    $sets[] = 'admin_notes = ?';
    $params[] = $notes;
  }
  if (has_column($pdo, 'orders', 'updated_at')) {
    $sets[] = 'updated_at = NOW()';
  }
  if (!$sets) return;

  $params[] = $orderId;
  $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
  $stmt->execute($params);
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

  update_order_delivery_state($pdo, $orderDbId, 'pending_delivery', [
    'request_id' => $request_id,
    'created_at' => gmdate('c'),
  ]);

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

  $to = (string)config_value('mail.order_to', '');
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

  $from = (string)config_value('mail.from', 'no-reply@localhost');
  $headers = "From: {$from}\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

  if ($to !== '') {
    @mail($to, $subject, $message, $headers);
  }

  // ===============================
  // POZIV N8N
  // ===============================

  $payload = [
    "request_id" => $request_id,
    "order_db_id" => $orderDbId,
    "reseller_email" => $reseller_email,
    "product_id" => $product_id,
    "product_name" => (string)$p["product_name"],
    "account_type" => (string)$p["account_type"],
    "price_rsd" => $price,
    "currency" => (string)$p["currency"],
    "customer_email" => $customer_email,
    "ts" => gmdate("c")
  ];

  $webhookUrl = (string)config_value('integrations.n8n_webhook', '');
  $webhookSecret = (string)config_value('integrations.n8n_webhook_secret', '');
  $webhookHeaders = $webhookSecret !== '' ? ["X-Reseller-Secret: {$webhookSecret}"] : [];
  $n8n = $webhookUrl !== ''
    ? post_json($webhookUrl, $payload, 12, $webhookHeaders)
    : ["ok" => false, "code" => 0, "err" => "Webhook not configured", "body" => null];

  update_order_delivery_state($pdo, $orderDbId, $n8n["ok"] ? 'delivered' : 'delivery_failed', [
    'request_id' => $request_id,
    'n8n_ok' => $n8n["ok"],
    'n8n_code' => $n8n["code"],
    'n8n_error' => $n8n["err"],
    'n8n_body' => is_string($n8n["body"]) ? substr($n8n["body"], 0, 2000) : null,
    'updated_at' => gmdate('c'),
  ], $n8n["ok"] ? '' : 'Automatska isporuka nije potvrđena. Proveriti n8n execution i stock.');

  echo json_encode([
    "ok"=>true,
    "request_id"=>$request_id,
    "charged_rsd"=>$price,
    "balance_rsd"=>$newBal,
    "n8n_ok"=>$n8n["ok"],
    "n8n_code"=>$n8n["code"],
    "delivery_status"=>$n8n["ok"] ? "delivered" : "delivery_failed"
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(["ok"=>false,"error"=>$e->getMessage()]);
}
