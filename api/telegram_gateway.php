<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
require_json_content_type();
$input = read_json_body();
$action = h_string($input['action'] ?? '');
$pdo = db();

if (!table_exists($pdo, 'telegram_bot_config') || !table_exists($pdo, 'telegram_admins')) {
  json_response(['ok' => false, 'error' => 'Telegram SQL migration is not installed.'], 503);
}
$hash = (string)$pdo->query('SELECT api_token_hash FROM telegram_bot_config WHERE id = 1')->fetchColumn();
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided = preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) ? trim($match[1]) : '';
if ($hash === '' || $provided === '' || !hash_equals($hash, hash('sha256', $provided))) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
// Apply any legacy security-table upgrades before financial transactions begin.
ensure_security_tables($pdo);

function tg_require_admin(PDO $pdo, int $chatId): array {
  $stmt = $pdo->prepare("SELECT ta.chat_id, ta.admin_id, au.username FROM telegram_admins ta JOIN admin_users au ON au.id = ta.admin_id WHERE ta.chat_id = ? AND au.status = 'active' LIMIT 1");
  $stmt->execute([$chatId]);
  $admin = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$admin) json_response(['ok' => false, 'error' => 'Nemaš pristup.'], 403);
  return $admin;
}

function tg_reseller(PDO $pdo, int $id): ?array {
  $name = has_column($pdo, 'resellers', 'display_name') ? 'display_name' : "'' AS display_name";
  $phone = has_column($pdo, 'resellers', 'phone') ? 'phone' : 'NULL AS phone';
  $discount = has_column($pdo, 'resellers', 'discount_percent') ? 'discount_percent' : '0 AS discount_percent';
  $stmt = $pdo->prepare("SELECT id, email, {$name}, {$phone}, status, balance_rsd, {$discount} FROM resellers WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function tg_set_conversation(PDO $pdo, int $chatId, string $state, array $payload = []): void {
  $pdo->prepare("INSERT INTO telegram_conversations (chat_id, state, payload_json, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE state = VALUES(state), payload_json = VALUES(payload_json), expires_at = VALUES(expires_at)")
    ->execute([$chatId, $state, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

try {
  if ($action === 'claim_update') {
    $updateId = filter_var($input['update_id'] ?? null, FILTER_VALIDATE_INT);
    if ($updateId === false || $updateId < 0) json_response(['ok' => false, 'error' => 'Invalid update id.'], 400);
    $insert = $pdo->prepare('INSERT IGNORE INTO telegram_updates (update_id) VALUES (?)');
    $insert->execute([$updateId]);
    json_response(['ok' => true, 'duplicate' => $insert->rowCount() === 0]);
  }

  if ($action === 'release_update') {
    $updateId = filter_var($input['update_id'] ?? null, FILTER_VALIDATE_INT);
    if ($updateId === false || $updateId < 0) json_response(['ok' => false, 'error' => 'Invalid update id.'], 400);
    $stmt = $pdo->prepare('DELETE FROM telegram_updates WHERE update_id = ?');
    $stmt->execute([$updateId]);
    json_response(['ok' => true]);
  }

  if ($action === 'heartbeat') {
    $error = trim((string)($input['error'] ?? ''));
    $column = ($input['service'] ?? '') === 'webhook' ? 'webhook_last_seen_at' : 'outbox_last_seen_at';
    $pdo->prepare("UPDATE telegram_bot_config SET {$column} = NOW(), last_error = COALESCE(?, last_error) WHERE id = 1")
      ->execute([$error !== '' ? safe_public_error($error) : null]);
    json_response(['ok' => true]);
  }

  if ($action === 'poll_outbox') {
    $pdo->beginTransaction();
    $stmt = $pdo->query("SELECT id, event_type, payload_json FROM telegram_outbox WHERE ((status = 'pending' AND available_at <= NOW()) OR (status = 'processing' AND claimed_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE))) ORDER BY id ASC LIMIT 20 FOR UPDATE");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $index => $item) {
      $pdo->prepare("UPDATE telegram_outbox SET status = 'processing', claimed_at = NOW() WHERE id = ?")->execute([(int)$item['id']]);
      $items[$index]['payload'] = json_decode((string)$item['payload_json'], true) ?: [];
      unset($items[$index]['payload_json']);
    }
    $admins = $pdo->query("SELECT ta.chat_id FROM telegram_admins ta JOIN admin_users au ON au.id = ta.admin_id WHERE au.status = 'active' ORDER BY ta.created_at ASC")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->commit();
    json_response(['ok' => true, 'items' => $items, 'chat_ids' => array_map('intval', $admins)]);
  }

  if ($action === 'outbox_ack') {
    $id = (int)($input['id'] ?? 0);
    $sent = !empty($input['sent']);
    $error = safe_public_error((string)($input['error'] ?? ''));
    if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid outbox id.'], 400);
    if ($sent) {
      $pdo->prepare("UPDATE telegram_outbox SET status = 'sent', attempts = attempts + 1, delivered_at = NOW(), last_error = NULL WHERE id = ? AND status = 'processing'")->execute([$id]);
    } else {
      $pdo->prepare("UPDATE telegram_outbox SET status = 'pending', attempts = attempts + 1, available_at = DATE_ADD(NOW(), INTERVAL 30 SECOND), claimed_at = NULL, last_error = ? WHERE id = ? AND status = 'processing'")->execute([$error !== '' ? $error : 'Telegram send failed', $id]);
      $pdo->prepare('UPDATE telegram_bot_config SET last_error = ? WHERE id = 1')->execute([$error !== '' ? $error : 'Telegram send failed']);
    }
    json_response(['ok' => true]);
  }

  if ($action === 'get_conversation') {
    $chatId = (int)($input['chat_id'] ?? 0);
    tg_require_admin($pdo, $chatId);
    $stmt = $pdo->prepare('SELECT state, payload_json FROM telegram_conversations WHERE chat_id = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([$chatId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    json_response(['ok' => true, 'conversation' => $row ? ['state' => $row['state'], 'payload' => json_decode((string)$row['payload_json'], true) ?: []] : null]);
  }

  if ($action === 'set_conversation') {
    $chatId = (int)($input['chat_id'] ?? 0);
    tg_require_admin($pdo, $chatId);
    $state = h_string($input['state'] ?? '');
    if (!in_array($state, ['await_amount', 'await_reason', 'await_totp', 'await_reject_reason', 'await_reseller_action'], true)) json_response(['ok' => false, 'error' => 'Invalid conversation state.'], 400);
    tg_set_conversation($pdo, $chatId, $state, is_array($input['payload'] ?? null) ? $input['payload'] : []);
    json_response(['ok' => true]);
  }

  if ($action === 'clear_conversation') {
    $chatId = (int)($input['chat_id'] ?? 0);
    tg_require_admin($pdo, $chatId);
    $pdo->prepare('DELETE FROM telegram_conversations WHERE chat_id = ?')->execute([$chatId]);
    json_response(['ok' => true]);
  }

  if ($action === 'authorized') {
    $chatId = (int)($input['chat_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT ta.label, au.username FROM telegram_admins ta JOIN admin_users au ON au.id = ta.admin_id WHERE ta.chat_id = ? AND au.status = 'active' LIMIT 1");
    $stmt->execute([$chatId]);
    $adminInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    json_response(['ok' => true, 'authorized' => (bool)$adminInfo, 'admin' => $adminInfo ?: null]);
  }

  if ($action === 'list_resellers') {
    $chatId = (int)($input['chat_id'] ?? 0);
    tg_require_admin($pdo, $chatId);
    $stmt = $pdo->query('SELECT id, email, balance_rsd, status' . (has_column($pdo, 'resellers', 'display_name') ? ', display_name' : ", '' AS display_name") . ' FROM resellers ORDER BY display_name ASC, email ASC LIMIT 250');
    json_response(['ok' => true, 'resellers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  $chatId = (int)($input['chat_id'] ?? 0);
  $admin = tg_require_admin($pdo, $chatId);
  $adminId = (int)$admin['admin_id'];

  if ($action === 'reseller') {
    $reseller = tg_reseller($pdo, (int)($input['reseller_id'] ?? 0));
    if (!$reseller) json_response(['ok' => false, 'error' => 'Reseller nije pronađen.'], 404);
    $twofa = 'nije dostupno';
    if (table_exists($pdo, 'reseller_two_factor')) {
      $tf = $pdo->prepare('SELECT enabled_at FROM reseller_two_factor WHERE reseller_id = ? LIMIT 1');
      $tf->execute([(int)$reseller['id']]);
      $twofa = $tf->fetchColumn() ? 'uključena' : 'isključena';
    }
    $tx = $pdo->prepare('SELECT id, type, amount_rsd, description, created_at FROM wallet_transactions WHERE reseller_id = ? ORDER BY id DESC LIMIT 3');
    $tx->execute([(int)$reseller['id']]);
    $orders = $pdo->prepare('SELECT o.id, o.product_id, o.price_rsd, o.created_at, o.reseller_paid, pp.product_name, pp.account_type FROM orders o LEFT JOIN product_prices pp ON pp.product_id = o.product_id WHERE o.reseller_id = ? ORDER BY o.id DESC LIMIT 5');
    $orders->execute([(int)$reseller['id']]);
    json_response(['ok' => true, 'reseller' => $reseller, 'two_factor' => $twofa, 'transactions' => $tx->fetchAll(PDO::FETCH_ASSOC), 'orders' => $orders->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'transactions') {
    $id = (int)($input['reseller_id'] ?? 0);
    $limit = max(1, min(25, (int)($input['limit'] ?? 10)));
    $optional = [];
    foreach (['source', 'balance_before_rsd', 'balance_after_rsd', 'related_order_id'] as $column) {
      $optional[] = has_column($pdo, 'wallet_transactions', $column) ? $column : 'NULL AS ' . $column;
    }
    $stmt = $pdo->prepare('SELECT id, type, amount_rsd, description, created_at, ' . implode(', ', $optional) . ' FROM wallet_transactions WHERE reseller_id = ? ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([$id]);
    json_response(['ok' => true, 'transactions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'orders') {
    $unpaid = !empty($input['unpaid']);
    $sql = 'SELECT o.id, o.reseller_id, o.price_rsd, o.created_at, o.reseller_paid, pp.product_name, pp.account_type, r.display_name, r.email FROM orders o LEFT JOIN product_prices pp ON pp.product_id = o.product_id LEFT JOIN resellers r ON r.id = o.reseller_id';
    if ($unpaid) $sql .= ' WHERE COALESCE(o.reseller_paid, 0) = 0';
    $sql .= ' ORDER BY o.id DESC LIMIT 20';
    json_response(['ok' => true, 'orders' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'payments') {
    $stmt = $pdo->query("SELECT p.id, p.reseller_id, p.reseller_email, p.balance_rsd, p.amount_rsd, p.clicked_at, p.status, p.review_status, r.display_name FROM payment_notice_requests p LEFT JOIN resellers r ON r.id = p.reseller_id WHERE p.review_status = 'pending' ORDER BY p.id DESC LIMIT 30");
    json_response(['ok' => true, 'payments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'debt') {
    $rows = $pdo->query("SELECT id, email, balance_rsd, status" . (has_column($pdo, 'resellers', 'display_name') ? ', display_name' : ", '' AS display_name") . ' FROM resellers WHERE balance_rsd < 0 ORDER BY balance_rsd ASC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    $sum = (int)$pdo->query('SELECT COALESCE(SUM(balance_rsd), 0) FROM resellers WHERE balance_rsd < 0')->fetchColumn();
    json_response(['ok' => true, 'resellers' => $rows, 'total_debt_rsd' => abs($sum)]);
  }

  if ($action === 'products') {
    $stmt = $pdo->query('SELECT product_id, product_name, account_type, price, currency, status FROM product_prices ORDER BY product_name ASC LIMIT 250');
    json_response(['ok' => true, 'products' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'today') {
    $orders = $pdo->query('SELECT COUNT(*) AS count, COALESCE(SUM(price_rsd),0) AS volume FROM orders WHERE DATE(created_at) = CURDATE()')->fetch(PDO::FETCH_ASSOC);
    $payments = $pdo->query("SELECT COUNT(*) AS count, COALESCE(SUM(amount_rsd),0) AS volume FROM wallet_transactions WHERE DATE(created_at) = CURDATE() AND amount_rsd > 0 AND type IN ('PAYMENT_RECEIVED','BANK_TRANSFER','CASH_PAYMENT','CARD_PAYMENT','OTHER_PAYMENT')")->fetch(PDO::FETCH_ASSOC);
    json_response(['ok' => true, 'orders' => $orders, 'payments' => $payments]);
  }

  if ($action === 'begin_action') {
    $kind = h_string($input['kind'] ?? '');
    $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
    $preview = [];
    if ($kind === 'balance_adjust') {
      $reseller = tg_reseller($pdo, (int)($payload['reseller_id'] ?? 0));
      $amount = (int)($payload['amount_rsd'] ?? 0);
      $reason = trim((string)($payload['reason'] ?? ''));
      if (!$reseller || $amount === 0 || abs($amount) > 100000000 || $reason === '' || strlen($reason) > 255) json_response(['ok' => false, 'error' => 'Proverite resellera, iznos i razlog.'], 400);
      $payload = ['reseller_id' => (int)$reseller['id'], 'amount_rsd' => $amount, 'reason' => $reason, 'type' => h_string($payload['type'] ?? ($amount > 0 ? 'ADMIN_TOPUP' : 'ADMIN_ADJUSTMENT'))];
      $preview = ['title' => (string)($reseller['display_name'] ?: $reseller['email']), 'before' => (int)$reseller['balance_rsd'], 'amount' => $amount, 'after' => (int)$reseller['balance_rsd'] + $amount, 'reason' => $reason, 'reseller_id' => (int)$reseller['id']];
    } elseif ($kind === 'payment_confirm') {
      $noticeId = (int)($payload['notice_id'] ?? 0);
      $amount = (int)($payload['amount_rsd'] ?? 0);
      $stmt = $pdo->prepare("SELECT p.*, r.balance_rsd, r.display_name FROM payment_notice_requests p JOIN resellers r ON r.id = p.reseller_id WHERE p.id = ? AND p.review_status = 'pending' LIMIT 1");
      $stmt->execute([$noticeId]);
      $notice = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$notice || $amount <= 0 || $amount > 100000000) json_response(['ok' => false, 'error' => 'Uplata nije pronađena ili iznos nije validan.'], 404);
      $payload = ['notice_id' => $noticeId, 'reseller_id' => (int)$notice['reseller_id'], 'amount_rsd' => $amount, 'reason' => 'Potvrđena uplata preko Telegrama · obaveštenje #' . $noticeId, 'type' => 'PAYMENT_RECEIVED'];
      $preview = ['title' => (string)($notice['display_name'] ?: $notice['reseller_email']), 'before' => (int)$notice['balance_rsd'], 'amount' => $amount, 'after' => (int)$notice['balance_rsd'] + $amount, 'reason' => $payload['reason'], 'reseller_id' => (int)$notice['reseller_id']];
    } elseif ($kind === 'reverse_transaction') {
      $originalId = (int)($payload['transaction_id'] ?? 0);
      $stmt = $pdo->prepare('SELECT wt.*, r.display_name, r.email FROM wallet_transactions wt JOIN resellers r ON r.id = wt.reseller_id WHERE wt.id = ? LIMIT 1');
      $stmt->execute([$originalId]);
      $original = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$original || (string)$original['type'] === 'REVERSAL' || (int)($original['reversal_of_transaction_id'] ?? 0) > 0) json_response(['ok' => false, 'error' => 'Transakcija ne postoji ili je već storno.'], 404);
      $reversalCheck = $pdo->prepare('SELECT id FROM wallet_transactions WHERE reversal_of_transaction_id = ? LIMIT 1');
      $reversalCheck->execute([$originalId]);
      if ($reversalCheck->fetchColumn()) json_response(['ok' => false, 'error' => 'Transakcija je već stornirana.'], 409);
      $payload = ['transaction_id' => $originalId, 'reseller_id' => (int)$original['reseller_id'], 'amount_rsd' => -(int)$original['amount_rsd'], 'reason' => 'Storno transakcije #' . $originalId . ': ' . (string)$original['description'], 'type' => 'REVERSAL'];
      $current = (int)$pdo->query('SELECT balance_rsd FROM resellers WHERE id = ' . (int)$original['reseller_id'])->fetchColumn();
      $preview = ['title' => (string)($original['display_name'] ?: $original['email']), 'before' => $current, 'amount' => (int)$payload['amount_rsd'], 'after' => $current + (int)$payload['amount_rsd'], 'reason' => $payload['reason'], 'reseller_id' => (int)$original['reseller_id']];
    } elseif ($kind === 'product_price') {
      $productId = h_string($payload['product_id'] ?? '');
      $price = (int)($payload['price'] ?? 0);
      if ($productId === '' || $price <= 0 || $price > 100000000) json_response(['ok' => false, 'error' => 'Proverite product_id i cenu.'], 400);
      $stmt = $pdo->prepare('SELECT product_id, product_name, account_type, price FROM product_prices WHERE product_id = ? LIMIT 1');
      $stmt->execute([$productId]);
      $product = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$product) json_response(['ok' => false, 'error' => 'Proizvod nije pronađen.'], 404);
      $payload = ['product_id' => $productId, 'price' => $price];
      $preview = ['title' => (string)$product['product_name'] . ' · ' . (string)$product['account_type'], 'before' => (int)$product['price'], 'after' => $price, 'reason' => 'Promena cene'];
    } elseif ($kind === 'product_status') {
      $productId = h_string($payload['product_id'] ?? '');
      $active = !empty($payload['active']);
      $stmt = $pdo->prepare('SELECT product_id, product_name, account_type FROM product_prices WHERE product_id = ? LIMIT 1');
      $stmt->execute([$productId]);
      $product = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$product) json_response(['ok' => false, 'error' => 'Proizvod nije pronađen.'], 404);
      $payload = ['product_id' => $productId, 'active' => $active];
      $preview = ['title' => (string)$product['product_name'] . ' · ' . (string)$product['account_type'], 'after' => $active ? 'Aktivan' : 'Neaktivan', 'reason' => 'Promena statusa proizvoda'];
    } elseif ($kind === 'mark_order_paid') {
      $orderId = (int)($payload['order_id'] ?? 0);
      if (!has_column($pdo, 'orders', 'reseller_paid')) json_response(['ok' => false, 'error' => 'Nedostaje reseller_paid kolona; pokrenite migraciju za order notes.'], 503);
      $stmt = $pdo->prepare('SELECT o.id, o.reseller_id, o.price_rsd, r.display_name, r.email, pp.product_name, pp.account_type FROM orders o JOIN resellers r ON r.id = o.reseller_id LEFT JOIN product_prices pp ON pp.product_id = o.product_id WHERE o.id = ? LIMIT 1');
      $stmt->execute([$orderId]);
      $order = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$order) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);
      $payload = ['order_id' => $orderId];
      $preview = ['title' => (string)($order['display_name'] ?: $order['email']), 'after' => 'Označeno plaćenim', 'reason' => '#' . $orderId . ' · ' . (string)$order['product_name'] . ' · ' . (int)$order['price_rsd'] . ' RSD'];
    } else {
      json_response(['ok' => false, 'error' => 'Nepoznata akcija.'], 400);
    }

    $actionId = bin2hex(random_bytes(16));
    $insert = $pdo->prepare("INSERT INTO telegram_pending_actions (action_id, chat_id, admin_id, action_type, payload_json, preview_json, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
    $insert->execute([$actionId, $chatId, $adminId, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode($preview, JSON_UNESCAPED_UNICODE)]);
    json_response(['ok' => true, 'action_id' => $actionId, 'preview' => $preview, 'expires_in' => 300]);
  }

  if ($action === 'set_action_message') {
    $actionId = h_string($input['action_id'] ?? '');
    $messageId = (int)($input['message_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE telegram_pending_actions SET telegram_message_id = ? WHERE action_id = ? AND chat_id = ? AND status = 'pending' AND expires_at > NOW()");
    $stmt->execute([$messageId, $actionId, $chatId]);
    json_response(['ok' => true]);
  }

  if ($action === 'cancel_action') {
    $actionId = h_string($input['action_id'] ?? '');
    $stmt = $pdo->prepare("UPDATE telegram_pending_actions SET status = 'cancelled' WHERE action_id = ? AND chat_id = ? AND status IN ('pending','awaiting_totp')");
    $stmt->execute([$actionId, $chatId]);
    json_response(['ok' => true, 'cancelled' => $stmt->rowCount() > 0]);
  }

  if ($action === 'confirm_action') {
    $actionId = h_string($input['action_id'] ?? '');
    $totpCode = trim((string)($input['totp_code'] ?? ''));
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare("SELECT * FROM telegram_pending_actions WHERE action_id = ? AND chat_id = ? LIMIT 1 FOR UPDATE");
      $stmt->execute([$actionId, $chatId]);
      $pending = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$pending) throw new RuntimeException('Potvrda nije pronađena.');
      if ((string)$pending['status'] === 'done') {
        $result = json_decode((string)$pending['result_json'], true) ?: [];
        $pdo->commit();
        json_response(['ok' => true, 'duplicate' => true, 'result' => $result]);
      }
      if (!in_array((string)$pending['status'], ['pending', 'awaiting_totp'], true)) throw new RuntimeException('Potvrda je već iskorišćena ili otkazana.');
      if (strtotime((string)$pending['expires_at']) < time()) {
        $pdo->prepare("UPDATE telegram_pending_actions SET status = 'expired' WHERE action_id = ?")->execute([$actionId]);
        $pdo->commit();
        json_response(['ok' => false, 'error' => 'Potvrda je istekla posle 5 minuta. Pokrenite komandu ponovo.'], 410);
      }
      $payload = json_decode((string)$pending['payload_json'], true) ?: [];
      $preview = json_decode((string)$pending['preview_json'], true) ?: [];
      $moneyAction = in_array((string)$pending['action_type'], ['balance_adjust', 'payment_confirm', 'reverse_transaction'], true);
      $amount = (int)($payload['amount_rsd'] ?? 0);
      $threshold = (int)$pdo->query('SELECT totp_threshold_rsd FROM telegram_bot_config WHERE id = 1')->fetchColumn();
      if ($moneyAction && abs($amount) > $threshold) {
        if (!admin_two_factor_enabled($pdo, $adminId)) throw new RuntimeException('Za iznos ovog praga prvo uključite Admin 2FA.');
        $tf = admin_two_factor_row($pdo, $adminId);
        $secret = decrypt_secret((string)($tf['secret_encrypted'] ?? ''));
        $counter = matching_totp_counter($secret, $totpCode);
        if ($counter === null) {
          $attempts = (int)$pending['totp_attempts'] + 1;
          $expired = $attempts >= 5;
          $pdo->prepare("UPDATE telegram_pending_actions SET status = ?, totp_attempts = ? WHERE action_id = ?")
            ->execute([$expired ? 'cancelled' : 'awaiting_totp', $attempts, $actionId]);
          $pdo->commit();
          if ($expired) json_response(['ok' => false, 'terminal' => true, 'error' => 'Previše pogrešnih 2FA kodova. Pokrenite akciju ponovo.'], 429);
          json_response(['ok' => true, 'requires_totp' => true, 'totp_error' => true, 'action_id' => $actionId]);
        }
        $lastStmt = $pdo->prepare('SELECT last_totp_step FROM admin_two_factor WHERE admin_id = ? FOR UPDATE');
        $lastStmt->execute([$adminId]);
        $lastCounter = $lastStmt->fetchColumn();
        if ($lastCounter !== false && $lastCounter !== null && (int)$lastCounter === $counter) throw new RuntimeException('Ovaj 2FA kod je već korišćen. Sačekajte novi kod.');
        $pdo->prepare('UPDATE admin_two_factor SET last_totp_step = ?, last_used_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([$counter, $adminId]);
      }

      $result = [];
      if (in_array((string)$pending['action_type'], ['balance_adjust', 'payment_confirm', 'reverse_transaction'], true)) {
        $type = (string)($payload['type'] ?? 'ADMIN_ADJUSTMENT');
        $metadata = ['source' => 'telegram', 'admin_id' => $adminId, 'admin_chat_id' => $chatId, 'telegram_message_id' => $pending['telegram_message_id'] ?: null, 'idempotency_key' => $actionId];
        if (!empty($payload['transaction_id'])) $metadata['reversal_of_transaction_id'] = (int)$payload['transaction_id'];
        $wallet = apply_wallet_transaction($pdo, (int)$payload['reseller_id'], $amount, $type, (string)$payload['reason'], $metadata);
        if ($pending['action_type'] === 'payment_confirm') {
          $update = $pdo->prepare("UPDATE payment_notice_requests SET amount_rsd = ?, review_status = 'confirmed', reviewed_at = NOW(), reviewed_by_admin_id = ?, review_reason = ? WHERE id = ? AND review_status = 'pending'");
          $update->execute([$amount, $adminId, (string)$payload['reason'], (int)$payload['notice_id']]);
          if ($update->rowCount() !== 1) throw new RuntimeException('Uplata je već obrađena u drugom prozoru.');
        }
        $result = ['transaction_id' => $wallet['transaction_id'], 'balance_before_rsd' => $wallet['balance_before_rsd'], 'balance_after_rsd' => $wallet['balance_after_rsd'], 'amount_rsd' => $amount];
      } elseif ($pending['action_type'] === 'product_price') {
        $pdo->prepare('UPDATE product_prices SET price = ? WHERE product_id = ?')->execute([(int)$payload['price'], (string)$payload['product_id']]);
        $result = ['product_id' => $payload['product_id'], 'price' => (int)$payload['price']];
      } elseif ($pending['action_type'] === 'product_status') {
        if (has_column($pdo, 'product_prices', 'status')) $pdo->prepare('UPDATE product_prices SET status = ? WHERE product_id = ?')->execute([!empty($payload['active']) ? 'active' : 'inactive', (string)$payload['product_id']]);
        elseif (has_column($pdo, 'product_prices', 'is_active')) $pdo->prepare('UPDATE product_prices SET is_active = ? WHERE product_id = ?')->execute([!empty($payload['active']) ? 1 : 0, (string)$payload['product_id']]);
        else throw new RuntimeException('Baza nema kolonu za aktivnost proizvoda.');
        $result = ['product_id' => $payload['product_id'], 'active' => !empty($payload['active'])];
      } elseif ($pending['action_type'] === 'mark_order_paid') {
        $sets = ['reseller_paid = 1'];
        if (has_column($pdo, 'orders', 'reseller_paid_at')) $sets[] = 'reseller_paid_at = NOW()';
        $update = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $update->execute([(int)$payload['order_id']]);
        $result = ['order_id' => (int)$payload['order_id'], 'paid' => true];
      }
      audit_event($pdo, 'admin', $adminId, 'telegram_' . (string)$pending['action_type'], 'success', [
        'source' => 'telegram', 'admin_chat_id' => $chatId, 'telegram_message_id' => $pending['telegram_message_id'],
        'action_id' => $actionId, 'preview' => $preview, 'result' => $result,
      ]);
      $pdo->prepare("UPDATE telegram_pending_actions SET status = 'done', result_json = ? WHERE action_id = ?")->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $actionId]);
      $pdo->commit();
      json_response(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      json_response(['ok' => false, 'error' => safe_public_error($e->getMessage())], 409);
    }
  }

  if ($action === 'reject_payment') {
    $noticeId = (int)($input['notice_id'] ?? 0);
    $reason = substr(trim((string)($input['reason'] ?? '')), 0, 255);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE payment_notice_requests SET review_status = 'rejected', reviewed_at = NOW(), reviewed_by_admin_id = ?, review_reason = ? WHERE id = ? AND review_status = 'pending'");
    $stmt->execute([$adminId, $reason !== '' ? $reason : null, $noticeId]);
    if ($stmt->rowCount() !== 1) { $pdo->rollBack(); json_response(['ok' => false, 'error' => 'Uplata je već obrađena ili nije pronađena.'], 409); }
    audit_event($pdo, 'admin', $adminId, 'telegram_payment_rejected', 'success', ['chat_id' => $chatId, 'notice_id' => $noticeId, 'reason' => $reason]);
    $pdo->commit();
    json_response(['ok' => true]);
  }

  if ($action === 'resolve_report') {
    $type = h_string($input['type'] ?? 'missing_game');
    $id = (int)($input['id'] ?? 0);
    if ($type === 'missing_game') {
      $stmt = $pdo->prepare("UPDATE missing_game_reports SET status = 'resolved', resolved_at = NOW(), resolved_by_admin_id = ?, updated_at = NOW() WHERE id = ? AND status <> 'resolved'");
    } elseif ($type === 'game_request') {
      $stmt = $pdo->prepare("UPDATE reseller_game_requests SET status = 'resolved', resolved_at = NOW(), resolved_by_admin_id = ? WHERE id = ? AND status <> 'resolved'");
    } else json_response(['ok' => false, 'error' => 'Invalid report type.'], 400);
    $stmt->execute([$adminId, $id]);
    if ($stmt->rowCount() !== 1) json_response(['ok' => false, 'error' => 'Prijava je već rešena ili nije pronađena.'], 409);
    audit_event($pdo, 'admin', $adminId, 'telegram_report_resolved', 'success', ['chat_id' => $chatId, 'report_type' => $type, 'report_id' => $id]);
    json_response(['ok' => true]);
  }

  if ($action === 'mark_paid') {
    $orderId = (int)($input['order_id'] ?? 0);
    if (!has_column($pdo, 'orders', 'reseller_paid')) json_response(['ok' => false, 'error' => 'Pokrenite SQL migraciju za reseller order notes.'], 503);
    $sets = ['reseller_paid = 1'];
    if (has_column($pdo, 'orders', 'reseller_paid_at')) $sets[] = 'reseller_paid_at = NOW()';
    $stmt = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute([$orderId]);
    if ($stmt->rowCount() !== 1) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena ili je već plaćena.'], 409);
    audit_event($pdo, 'admin', $adminId, 'telegram_order_marked_paid', 'success', ['chat_id' => $chatId, 'order_id' => $orderId]);
    json_response(['ok' => true]);
  }

  if ($action === 'inventory_status') {
    $stmt = $pdo->query("SELECT result, http_status, error_message, created_at FROM inventory_api_requests ORDER BY id DESC LIMIT 1");
    json_response(['ok' => true, 'last_error' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);
  }

  json_response(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => safe_public_error($e->getMessage())], 500);
}
