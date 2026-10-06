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
$responseSent = false;

function update_order_delivery_state(PDO $pdo, int $orderId, string $status, array $payload = [], string $notes = ''): void {
  $sets = [];
  $params = [];
  $columns = column_names($pdo, 'orders');

  if (in_array('status', $columns, true)) {
    $sets[] = 'status = ?';
    $params[] = $status;
  }
  if (in_array('delivery_payload', $columns, true)) {
    $sets[] = 'delivery_payload = ?';
    $params[] = json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
  if ($notes !== '' && in_array('admin_notes', $columns, true)) {
    $sets[] = 'admin_notes = ?';
    $params[] = $notes;
  }
  if (in_array('updated_at', $columns, true)) {
    $sets[] = 'updated_at = NOW()';
  }
  if (!$sets) return;

  $params[] = $orderId;
  $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
  $stmt->execute($params);
}

$pdo = db();
$profile = reseller_profile($pdo, $reseller_id);
if (!$profile) {
  json_response(["ok"=>false,"error"=>"Nalog nije pronađen."], 404);
}
if (!array_key_exists('profile_completed_at', $profile)) {
  try {
    ensure_security_tables($pdo);
    $profile = reseller_profile($pdo, $reseller_id) ?: $profile;
  } catch (Throwable $ignored) {
    // Keep legacy databases usable if runtime schema updates are unavailable.
  }
}
if (!table_exists($pdo, 'order_delivery_events')) {
  try {
    ensure_order_observability_tables($pdo);
  } catch (Throwable $ignored) {
    // Order persistence must not depend on optional notification audit tables.
  }
}
if (array_key_exists('profile_completed_at', $profile) && (
  (string)($profile['profile_completed_at'] ?? '') === '' ||
  !valid_email((string)($profile['email'] ?? '')) ||
  is_internal_reseller_email((string)($profile['email'] ?? '')) ||
  (array_key_exists('phone', $profile) && !valid_phone((string)($profile['phone'] ?? '')))
)) {
  json_response([
    'ok' => false,
    'error' => 'Dovršite podešavanja naloga pre nastavka korišćenja panela.',
    'profile_required' => true,
  ], 428);
}
if ($profile) {
  $reseller_name = (string)($profile["display_name"] ?? "");
  $reseller_phone = (string)($profile["phone"] ?? "");
}
$customer_email = normalize_email((string)($profile["email"] ?? $reseller_email));
$discountPercent = normalized_discount_percent($profile["discount_percent"] ?? 0);
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

  $basePrice = (int)$p["price"];
  $price = discounted_price($basePrice, $discountPercent);
  if ($basePrice <= 0 || $price <= 0) {
    throw new RuntimeException("Proizvod trenutno nema validnu cenu.", 422);
  }
  $desc  = "Order: ".$p["product_name"]." / ".$p["account_type"];

  $balanceStmt = $pdo->prepare("SELECT balance_rsd FROM resellers WHERE id=? FOR UPDATE");
  $balanceStmt->execute([$reseller_id]);
  $currentBalance = $balanceStmt->fetchColumn();
  if ($currentBalance === false) {
    throw new RuntimeException("Nalog nije pronađen.", 404);
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

  // Record the debit and balance snapshot through the shared atomic wallet service.
  $wallet = apply_wallet_transaction($pdo, $reseller_id, -$price, 'ORDER', $desc, [
    'related_order_id' => $orderDbId,
    'source' => 'panel',
  ]);
  $newBal = (int)$wallet['balance_after_rsd'];

  telegram_enqueue($pdo, 'order-' . $orderDbId, 'new_order', [
    'order_id' => $orderDbId, 'reseller_id' => $reseller_id, 'reseller_email' => $reseller_email,
    'reseller_name' => $reseller_name, 'product_name' => (string)$p['product_name'],
    'account_type' => (string)$p['account_type'], 'price_rsd' => $price, 'balance_rsd' => $newBal,
  ]);
  $pdo->commit();

  // Return as soon as the order and wallet charge are durable. PHP-FPM can
  // continue email/webhook work after the browser has received this response.
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
  }
  $successResponse = json_encode([
    "ok"=>true,
    "request_id"=>$request_id,
    "charged_rsd"=>$price,
    "balance_rsd"=>$newBal,
    "email_ok"=>null,
    "n8n_ok"=>null,
    "delivery_status"=>"processing"
  ], JSON_UNESCAPED_UNICODE);
  if (function_exists('fastcgi_finish_request')) {
    ignore_user_abort(true);
    @set_time_limit(30);
    echo $successResponse;
    fastcgi_finish_request();
    $responseSent = true;
  } else {
    // Let the browser finish reading the JSON before slower notification work.
    ignore_user_abort(true);
    @set_time_limit(30);
    header('Connection: close');
    header('Content-Length: ' . strlen($successResponse));
    echo $successResponse;
    while (ob_get_level() > 0) {
      if (!@ob_end_flush()) break;
    }
    flush();
    $responseSent = true;
  }

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
    'base_price_rsd' => $basePrice,
    'discount_percent' => $discountPercent,
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
    "base_price_rsd" => $basePrice,
    "discount_percent" => $discountPercent,
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

  if (!$responseSent) {
    echo json_encode([
      "ok"=>true,
      "request_id"=>$request_id,
      "charged_rsd"=>$price,
      "balance_rsd"=>$newBal,
      "email_ok"=>$mailResult["ok"],
      "n8n_ok"=>$n8n["ok"],
      "n8n_code"=>$n8n["code"],
      "delivery_status"=>$n8n["ok"] ? "delivered" : "delivery_failed"
    ], JSON_UNESCAPED_UNICODE);
  }

} catch (Throwable $e) {
  if ($responseSent) {
    error_log('Order post-commit processing failed for order request ' . ($request_id ?? 'unknown') . ': ' . safe_public_error($e->getMessage()));
    exit;
  }
  if ($pdo->inTransaction()) $pdo->rollBack();
  $status = (int)$e->getCode();
  if ($status < 400 || $status > 499) $status = 500;
  http_response_code($status);
  $message = $status === 422 || $status === 404 || $status === 409
    ? $e->getMessage()
    : "Porudžbina trenutno nije mogla da se obradi.";
  echo json_encode(["ok"=>false,"error"=>$message], JSON_UNESCAPED_UNICODE);
}
