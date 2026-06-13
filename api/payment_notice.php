<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_csrf();
$reseller = require_reseller();

try {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id, email, balance_rsd FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$reseller['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_response(['ok' => false, 'error' => 'Reseller not found'], 404);
  }

  $clickedAt = gmdate('Y-m-d H:i:s') . ' UTC';
  $subject = 'Reseller je označio uplatu';
  $message = "Reseller je kliknuo dugme \"Uplatio sam\" i označio da je izvršio uplatu.\n\n";
  $message .= 'Reseller ID: ' . (int)$row['id'] . "\n";
  $message .= 'Reseller Email: ' . (string)$row['email'] . "\n";
  $message .= 'Trenutni balance: ' . (int)$row['balance_rsd'] . " RSD\n";
  $message .= 'Vreme klika: ' . $clickedAt . "\n\n";
  $message .= "Potrebno je proveriti uplatu i po potrebi ažurirati balance u admin panelu.\n";

  $headers = "From: no-reply@psigre.rs\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

  $sent = @mail('arsenijee19@gmail.com', $subject, $message, $headers);

  json_response(['ok' => true, 'mail_sent' => $sent]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri slanju obaveštenja.'], 500);
}
