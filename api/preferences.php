<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$reseller = require_reseller();
$resellerId = (int)$reseller['id'];

try {
  $pdo = db();
  ensure_security_tables($pdo);

  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'preferences' => reseller_preferences($pdo, $resellerId), 'csrf_token' => csrf_token()]);
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
  }

  require_same_origin();
  require_json_content_type();
  require_csrf();
  $input = read_json_body();
  ensure_reseller_preference_columns($pdo);

  $fields = [];
  $params = [];
  if (array_key_exists('paid_tracking', $input)) {
    $fields[] = 'pref_paid_tracking = ?';
    $params[] = !empty($input['paid_tracking']) ? 1 : 0;
  }
  if (array_key_exists('order_notify_email', $input)) {
    // Empty means "use the account email".
    $email = normalize_email((string)$input['order_notify_email']);
    if ($email !== '' && !valid_email($email)) {
      json_response(['ok' => false, 'error' => 'Unesite validnu email adresu za obaveštenja.'], 400);
    }
    $fields[] = 'order_notify_email = ?';
    $params[] = $email !== '' ? $email : null;
  }
  if (array_key_exists('order_notify_enabled', $input)) {
    $fields[] = 'order_notify_enabled = ?';
    $params[] = !empty($input['order_notify_enabled']) ? 1 : 0;
  }
  if (!$fields) json_response(['ok' => false, 'error' => 'Nema izmena.'], 400);

  $params[] = $resellerId;
  $pdo->prepare('UPDATE resellers SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
  $preferences = reseller_preferences($pdo, $resellerId);
  audit_event($pdo, 'reseller', $resellerId, 'preferences_updated', 'success', [
    'paid_tracking' => $preferences['paid_tracking'],
    'order_notify_enabled' => $preferences['order_notify_enabled'],
    'order_notify_custom_email' => $preferences['order_notify_email'] !== '',
  ]);
  json_response(['ok' => true, 'preferences' => $preferences, 'csrf_token' => csrf_token()]);
} catch (Throwable $e) {
  json_response(['ok' => false, 'error' => 'Podešavanja trenutno nisu sačuvana. Pokušajte ponovo.'], 500);
}
