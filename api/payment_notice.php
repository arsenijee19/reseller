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

  $clickedAt = gmdate('Y-m-d H:i:s') . ' UTC';
  $subject = 'Reseller je označio uplatu';
  $message = "Reseller je kliknuo dugme \"Uplatio sam\" i označio da je izvršio uplatu.\n\n";
  $message .= 'Reseller ID: ' . (int)$row['id'] . "\n";
  $message .= 'Reseller Email: ' . (string)$row['email'] . "\n";
  $message .= 'Trenutni balance: ' . (int)$row['balance_rsd'] . " RSD\n";
  $message .= 'Vreme klika: ' . $clickedAt . "\n\n";
  $message .= "Potrebno je proveriti uplatu i po potrebi ažurirati balance u admin panelu.\n";

  $recipients = notification_recipients('mail.payment_notice_to', [
    'arsenijee19@gmail.com',
    'support@licenca.rs',
  ]);
  $noticeId = null;
  if (table_exists($pdo, 'payment_notice_requests')) {
    $insert = $pdo->prepare('INSERT INTO payment_notice_requests (reseller_id, reseller_email, balance_rsd, clicked_at, status, recipients, attempts) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
      (int)$row['id'],
      (string)$row['email'],
      (int)$row['balance_rsd'],
      gmdate('Y-m-d H:i:s'),
      'pending',
      implode(', ', $recipients),
      0,
    ]);
    $noticeId = (int)$pdo->lastInsertId();
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
  json_response(['ok' => false, 'error' => 'Greška pri slanju obaveštenja.'], 500);
}
