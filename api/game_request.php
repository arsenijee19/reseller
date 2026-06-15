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

$input = read_json_body();
$suggestion = trim((string)($input['suggestion'] ?? ''));

if ($suggestion === '') {
  json_response(['ok' => false, 'error' => 'Unesi naziv igre ili predlog.'], 400);
}

$suggestionLength = function_exists('mb_strlen') ? mb_strlen($suggestion, 'UTF-8') : strlen($suggestion);
if ($suggestionLength > 1000) {
  json_response(['ok' => false, 'error' => 'Predlog može imati najviše 1000 karaktera.'], 400);
}

try {
  $pdo = db();
  $stmt = $pdo->prepare('SELECT id, email, balance_rsd FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$reseller['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen. Refrešujte stranicu i ulogujte se ponovo.'], 404);
  }

  $subject = 'Reseller je zatražio novu igru';
  $message = "Reseller je poslao predlog igre za dodavanje u isporuku.\n\n";
  $message .= 'Reseller ID: ' . (int)$row['id'] . "\n";
  $message .= 'Reseller Email: ' . (string)$row['email'] . "\n";
  $message .= 'Trenutni balance: ' . (int)$row['balance_rsd'] . " RSD\n";
  $message .= 'Vreme: ' . gmdate('Y-m-d H:i:s') . " UTC\n\n";
  $message .= "Predlog:\n" . $suggestion . "\n";

  $from = (string)config_value('mail.from', 'no-reply@localhost');
  $headers = "From: {$from}\r\n";
  $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

  $sent = @mail('arsenijee19@gmail.com', $subject, $message, $headers);

  json_response(['ok' => true, 'mail_sent' => $sent]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Greška pri slanju predloga.'], 500);
}
