<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auto_delivery_note_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
require_json_content_type();
$input = read_json_body();

$orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
$resellerId = filter_var($input['reseller_id'] ?? null, FILTER_VALIDATE_INT);
$issuedAt = filter_var($input['issued_at'] ?? null, FILTER_VALIDATE_INT);
$requestId = strtolower(trim((string)($input['request_id'] ?? '')));
$signature = strtolower(trim((string)($input['signature'] ?? '')));
$loginEmail = trim((string)($input['login_email'] ?? ''));

if ($orderId === false || $orderId <= 0 || $resellerId === false || $resellerId <= 0
  || $issuedAt === false || !preg_match('/^[a-f0-9]{32}$/', $requestId)
  || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
  json_response(['ok' => false, 'error' => 'Podaci o isporuci nisu validni.'], 400);
}
if ($issuedAt < time() - 604800 || $issuedAt > time() + 60) {
  json_response(['ok' => false, 'error' => 'Potvrda isporuke je istekla.'], 401);
}

$message = delivery_callback_message((int)$orderId, (int)$resellerId, $requestId, (int)$issuedAt);
$expectedSignature = hash_hmac('sha256', $message, security_encryption_key());
if (!hash_equals($expectedSignature, $signature)) {
  json_response(['ok' => false, 'error' => 'Potpis isporuke nije validan.'], 401);
}

try {
  $note = append_auto_delivery_note('', $loginEmail);
  $pdo = db();
  if (!has_column($pdo, 'orders', 'reseller_notes') || !has_column($pdo, 'orders', 'request_id')) {
    json_response(['ok' => false, 'error' => 'Potrebna je reseller notes migracija.'], 503);
  }

  $find = $pdo->prepare('SELECT reseller_notes FROM orders WHERE id = ? AND reseller_id = ? AND request_id = ? LIMIT 1');
  $find->execute([(int)$orderId, (int)$resellerId, $requestId]);
  $current = $find->fetch(PDO::FETCH_ASSOC);
  if (!$current) json_response(['ok' => false, 'error' => 'Porudžbina nije pronađena.'], 404);

  $existingNotes = (string)($current['reseller_notes'] ?? '');
  $note = append_auto_delivery_note($existingNotes, $loginEmail);
  if ($note['already_recorded']) {
    if (!$note['same_email']) json_response(['ok' => false, 'error' => 'Za porudžbinu je već evidentiran drugi login email.'], 409);
    json_response(['ok' => true, 'duplicate' => true]);
  }

  $sets = ['reseller_notes = ?'];
  if (has_column($pdo, 'orders', 'updated_at')) $sets[] = 'updated_at = NOW()';
  $update = $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets)
    . ' WHERE id = ? AND reseller_id = ? AND request_id = ? AND reseller_notes <=> ?');
  $update->execute([$note['notes'], (int)$orderId, (int)$resellerId, $requestId, $current['reseller_notes']]);
  if ($update->rowCount() !== 1) {
    json_response(['ok' => false, 'error' => 'Beleška je istovremeno izmenjena; isporuka nije upisana.'], 409);
  }

  json_response(['ok' => true, 'duplicate' => false]);
} catch (InvalidArgumentException $e) {
  json_response(['ok' => false, 'error' => 'Login email nije validan.'], 400);
} catch (LengthException $e) {
  json_response(['ok' => false, 'error' => 'Postojeća beleška je predugačka za automatski dodatak.'], 409);
} catch (Throwable $e) {
  error_log('Auto delivery note callback failed for order ' . (int)$orderId . '.');
  json_response(['ok' => false, 'error' => 'Automatska beleška trenutno nije sačuvana.'], 500);
}
