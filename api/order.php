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

require_same_origin();
require_json_content_type();

require_csrf();

$input = read_json_body();
$product_id = trim((string)($input["product_id"] ?? ""));

if ($product_id === "") {
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"Missing product_id"]); exit;
}

$reseller_id = (int)$reseller["id"];
$reseller_email = (string)$reseller["email"];
$reseller_name = "";
$reseller_phone = "";

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
  ensure_order_observability_tables($pdo);
} catch (Throwable $ignored) {
  // Order creation must remain available even if a legacy DB user cannot create the observability tables.
}
require_completed_profile($pdo, $reseller_id);
$profile = reseller_profile($pdo, $reseller_id);
if ($profile) {
  $reseller_name = (string)($profile["display_name"] ?? "");
  $reseller_phone = (string)($profile["phone"] ?? "");
}
$customer_email = normalize_email((string)($profile["email"] ?? $reseller_email));
if (!valid_email($customer_email)) {
  throw new RuntimeException("Email resellera nije validan. Otvorite Nalog i proverite email adresu.");
}

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
  if ($price <= 0) {
    throw new RuntimeException("Proizvod trenutno nema validnu cenu.", 422);
  }
  $desc  = "Order: ".$p["product_name"]." / ".$p["account_type"];

  $balanceStmt = $pdo->prepare("SELECT balance_rsd FROM resellers WHERE id=? FOR UPDATE");
  $balanceStmt->execute([$reseller_id]);
  $currentBalance = $balanceStmt->fetchColumn();
  if ($currentBalance === false) {
    throw new RuntimeException("Nalog nije pronađen.", 404);
  }
  if ((int)$currentBalance < $price) {
    throw new RuntimeException("Nemate dovoljno sredstava za izabrani proizvod.", 422);
  }

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
  $up = $pdo->prepare("UPDATE resellers SET balance_rsd = balance_rsd - ? WHERE id=? AND balance_rsd >= ?");
  $up->execute([$price, $reseller_id, $price]);
  if ($up->rowCount() !== 1) {
    throw new RuntimeException("Balance se promenio tokom poručivanja. Osvežite stranicu i pokušajte ponovo.", 409);
  }

  // 6) Novi balans
  $b = $pdo->prepare("SELECT balance_rsd FROM resellers WHERE id=?");
  $b->execute([$reseller_id]);
  $newBal = (int)$b->fetchColumn();

  $pdo->commit();

  $orderEmail = order_notification([
    'product_id' => $product_id,
    'product_name' => (string)$p['product_name'],
    'account_type' => (string)$p['account_type'],
    'price_rsd' => $price,
    'reseller_id' => $reseller_id,
    'reseller_email' => $reseller_email,
    'reseller_name' => $reseller_name,
    'reseller_phone' => $reseller_phone,
    'buyer_email' => $customer_email,
  ]);
  $orderEmailRecipients = $orderEmail['recipients'];

  $mailResult = ['ok' => false, 'recipients' => $orderEmailRecipients, 'error' => 'Email nije obrađen.'];
  try {
    $mailResult = send_text_notification_email($orderEmailRecipients, $orderEmail['subject'], $orderEmail['message']);
    record_order_delivery_event($pdo, $orderDbId, 'email', $mailResult['ok'] ? 'sent' : 'failed', [
      'recipients' => $mailResult['recipients'],
      'error' => $mailResult['error'],
    ]);
  } catch (Throwable $ignored) {
    // The database order is authoritative; email failure is retained as a delivery event when possible.
    try {
      record_order_delivery_event($pdo, $orderDbId, 'email', 'failed', [
        'recipients' => $orderEmailRecipients,
        'error' => 'Email servis je bacio grešku.',
      ]);
    } catch (Throwable $ignoredEvent) {}
  }

  // ===============================
  // POZIV N8N
  // ===============================

  $payload = [
    "request_id" => $request_id,
    "order_db_id" => $orderDbId,
    "reseller_email" => $reseller_email,
    "reseller_name" => $reseller_name,
    "reseller_phone" => $reseller_phone,
    "product_id" => $product_id,
    "product_name" => (string)$p["product_name"],
    "account_type" => (string)$p["account_type"],
    "price_rsd" => $price,
    "currency" => (string)$p["currency"],
    "customer_email" => $customer_email,
    "ts" => gmdate("c")
  ];

  $webhookUrl = (string)config_value('integrations.n8n_webhook', '');
  try {
    $n8n = $webhookUrl !== ''
      ? post_json($webhookUrl, $payload)
      : ["ok" => false, "code" => 0, "err" => "Webhook not configured", "body" => null];
  } catch (Throwable $e) {
    $n8n = ["ok" => false, "code" => 0, "err" => safe_public_error($e->getMessage()), "body" => null];
  }

  try {
    record_order_delivery_event($pdo, $orderDbId, 'n8n', $n8n["ok"] ? 'accepted' : 'failed', [
      'http_status' => $n8n["code"],
      'error' => $n8n["err"],
      'payload' => $payload,
    ]);
    update_order_delivery_state($pdo, $orderDbId, $n8n["ok"] ? 'delivered' : 'delivery_failed', [
      'request_id' => $request_id,
      'n8n_ok' => $n8n["ok"],
      'n8n_code' => $n8n["code"],
      'n8n_error' => $n8n["err"],
      'n8n_body' => is_string($n8n["body"]) ? substr($n8n["body"], 0, 2000) : null,
      'updated_at' => gmdate('c'),
    ], '');
  } catch (Throwable $ignored) {
    // A notification/status write must never turn a committed order into a false client error.
  }

  echo json_encode([
    "ok"=>true,
    "request_id"=>$request_id,
    "charged_rsd"=>$price,
    "balance_rsd"=>$newBal,
    "email_ok"=>$mailResult["ok"],
    "n8n_ok"=>$n8n["ok"],
    "n8n_code"=>$n8n["code"],
    "delivery_status"=>$n8n["ok"] ? "delivered" : "delivery_failed"
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $status = (int)$e->getCode();
  if ($status < 400 || $status > 499) $status = 500;
  http_response_code($status);
  $message = $status === 422 || $status === 404 || $status === 409
    ? $e->getMessage()
    : "Porudžbina trenutno nije mogla da se obradi.";
  echo json_encode(["ok"=>false,"error"=>$message], JSON_UNESCAPED_UNICODE);
}
