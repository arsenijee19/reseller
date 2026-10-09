<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_same_origin();
require_json_content_type();

require_csrf();
$reseller = require_reseller();
$input = read_json_body();
$reportedAmount = null;
if (array_key_exists('amount_rsd', $input) && trim((string)$input['amount_rsd']) !== '') {
  $reportedAmount = filter_var($input['amount_rsd'], FILTER_VALIDATE_INT);
  if ($reportedAmount === false || $reportedAmount <= 0 || $reportedAmount > 100000000) {
    json_response(['ok' => false, 'error' => 'Iznos uplate nije validan.'], 400);
  }
}

try {
  $pdo = db();
  try {
    ensure_order_observability_tables($pdo);
  } catch (Throwable $ignored) {
    // Keep the notice flow usable on legacy installations; the migration enables the admin history.
  }
  require_completed_profile($pdo, (int)$reseller['id']);
  $stmt = $pdo->prepare('SELECT id, email, balance_rsd FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$reseller['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen. Refrešujte stranicu i ulogujte se ponovo.'], 404);
  }
  $profile = reseller_profile($pdo, (int)$row['id']) ?: [];

  $clickedAt = gmdate('Y-m-d H:i:s') . ' UTC';
  $subject = 'Reseller je označio uplatu';
  $message = "Reseller je kliknuo dugme \"Uplatio sam\" i označio da je izvršio uplatu.\n\n";
  $message .= 'Reseller ID: ' . (int)$row['id'] . "\n";
  $message .= 'Reseller Email: ' . (string)$row['email'] . "\n";
  $message .= 'Trenutni balance: ' . (int)$row['balance_rsd'] . " RSD\n";
  $message .= 'Prijavljeni iznos: ' . ($reportedAmount === null ? 'nije unet' : number_format($reportedAmount, 0, ',', '.') . ' RSD') . "\n";
  $message .= 'Vreme klika: ' . $clickedAt . "\n\n";
  $message .= "Potrebno je proveriti uplatu i po potrebi ažurirati balance u admin panelu.\n";

  $recipients = notification_recipients('mail.payment_notice_to', [
    'arsenijee19@gmail.com',
    'support@licenca.rs',
  ]);
  $noticeId = null;
  if (table_exists($pdo, 'payment_notice_requests')) {
    $insert = $pdo->prepare('INSERT INTO payment_notice_requests (reseller_id, reseller_email, balance_rsd, clicked_at, status, recipients, attempts' . (has_column($pdo, 'payment_notice_requests', 'amount_rsd') ? ', amount_rsd' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?' . (has_column($pdo, 'payment_notice_requests', 'amount_rsd') ? ', ?' : '') . ')');
    $insertValues = [
      (int)$row['id'],
      (string)$row['email'],
      (int)$row['balance_rsd'],
      gmdate('Y-m-d H:i:s'),
      'pending',
      implode(', ', $recipients),
      0,
    ];
    if (has_column($pdo, 'payment_notice_requests', 'amount_rsd')) $insertValues[] = $reportedAmount;
    $insert->execute($insertValues);
    $noticeId = (int)$pdo->lastInsertId();
    if ($noticeId > 0) {
      telegram_enqueue($pdo, 'payment-' . $noticeId, 'payment_notice', [
        'notice_id' => $noticeId,
        'reseller_id' => (int)$row['id'],
        'reseller_email' => (string)$row['email'],
        'reseller_name' => (string)($profile['display_name'] ?? ''),
        'balance_rsd' => (int)$row['balance_rsd'],
        'amount_rsd' => $reportedAmount,
      ]);
    }
  }

  // The notice is durably stored, so answer immediately and send the email afterwards;
  // SMTP latency used to make the "Uplatio sam" button feel stuck.
  if ($noticeId !== null) {
    $csrf = csrf_token();
    $earlyResponse = json_encode([
      'ok' => true,
      'notice_id' => $noticeId,
      'notice_recorded' => true,
      'mail_sent' => null,
      'message' => 'Obaveštenje je evidentirano i šalje se adminu.',
      'csrf_token' => $csrf,
    ], JSON_UNESCAPED_UNICODE);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    @set_time_limit(30);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if (function_exists('fastcgi_finish_request')) {
      echo $earlyResponse;
      fastcgi_finish_request();
    } else {
      header('Connection: close');
      header('Content-Length: ' . strlen($earlyResponse));
      echo $earlyResponse;
      while (ob_get_level() > 0) {
        if (!@ob_end_flush()) break;
      }
      flush();
    }
    $deferred = true;
  }

  $result = send_text_notification_email($recipients, $subject, $message);
  if ($noticeId !== null) {
    $update = $pdo->prepare('UPDATE payment_notice_requests SET status = ?, attempts = attempts + 1, error_message = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([
      $result['ok'] ? 'sent' : 'failed',
      $result['ok'] ? null : safe_public_error((string)$result['error']),
      $noticeId,
    ]);
  }

  try {
    audit_event($pdo, 'reseller', (int)$row['id'], 'payment_notice_created', $result['ok'] ? 'success' : 'notification_failed', [
      'notice_id' => $noticeId,
      'notification_sent' => $result['ok'],
    ]);
  } catch (Throwable $ignored) {}

  if (!empty($deferred)) exit;

  json_response([
    'ok' => true,
    'notice_id' => $noticeId,
    'notice_recorded' => $noticeId !== null,
    'mail_sent' => $result['ok'],
    'message' => $noticeId === null
      ? ($result['ok']
        ? 'Email je prihvaćen za slanje, ali istorija nije sačuvana. Proverite bazu i cPanel logove.'
        : 'Obaveštenje trenutno nije potvrđeno. Osvežite stranicu i pokušajte ponovo.')
      : ($result['ok']
        ? 'Obaveštenje je evidentirano i poslato adminu.'
        : 'Obaveštenje je evidentirano, ali email trenutno nije potvrđen. Admin ga vidi u panelu.'),
    'csrf_token' => csrf_token(),
  ]);
} catch (Throwable $e) {
  if (!empty($deferred)) {
    error_log('payment_notice_deferred_failed class=' . get_class($e));
    exit;
  }
  json_response(['ok' => false, 'error' => 'Greška pri slanju obaveštenja.'], 500);
}
