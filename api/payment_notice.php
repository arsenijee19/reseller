<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session();

$method = $_SERVER['REQUEST_METHOD'];

// GET: the reseller's own recent payment reports, shown under the "Uplatio sam" form.
if ($method === 'GET') {
  $reseller = require_reseller();
  try {
    $pdo = db();
    ensure_payment_proof_columns($pdo);
    $stmt = $pdo->prepare('SELECT id, amount_rsd, review_status, review_reason, created_at, reviewed_at, proof_file IS NOT NULL AS has_proof FROM payment_notice_requests WHERE reseller_id = ? ORDER BY id DESC LIMIT 5');
    $stmt->execute([(int)$reseller['id']]);
    $notices = array_map(static fn($row) => [
      'id' => (int)$row['id'],
      'amount_rsd' => $row['amount_rsd'] === null ? null : (int)$row['amount_rsd'],
      'review_status' => (string)$row['review_status'],
      'review_reason' => $row['review_reason'] === null ? null : (string)$row['review_reason'],
      'created_at' => (string)$row['created_at'],
      'reviewed_at' => $row['reviewed_at'] === null ? null : (string)$row['reviewed_at'],
      'has_proof' => (bool)$row['has_proof'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    json_response(['ok' => true, 'notices' => $notices, 'max_bytes' => PAYMENT_PROOF_MAX_BYTES]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Prijave uplata trenutno nisu dostupne.'], 500);
  }
}

if ($method !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

require_same_origin();
$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
if (strpos($contentType, 'multipart/form-data') !== 0) {
  json_response(['ok' => false, 'error' => 'Osvežite stranicu i pošaljite uplatu sa dokazom.'], 415);
}

require_csrf();
$reseller = require_reseller();

// A body above post_max_size arrives with empty $_POST/$_FILES, so check it explicitly.
$postMax = ini_parse_quantity_safe((string)ini_get('post_max_size'));
if ($postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
  json_response(['ok' => false, 'error' => 'Dokaz je prevelik. Pošaljite sliku ili PDF do 10 MB.'], 413);
}

$amountRaw = trim((string)($_POST['amount_rsd'] ?? ''));
$amountDigits = preg_replace('/[\s.]/', '', $amountRaw);
$amount = filter_var($amountDigits, FILTER_VALIDATE_INT);
if ($amountRaw === '' || $amount === false || $amount <= 0 || $amount > 100000000) {
  json_response(['ok' => false, 'error' => 'Unesite iznos uplate u dinarima.'], 400);
}

$file = $_FILES['proof'] ?? null;
if (!is_array($file) || is_array($file['error'] ?? null) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
  json_response(['ok' => false, 'error' => 'Priložite dokaz uplate (sliku ili PDF).'], 400);
}
$uploadError = (int)$file['error'];
if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$file['size'] > PAYMENT_PROOF_MAX_BYTES) {
  json_response(['ok' => false, 'error' => 'Dokaz je prevelik. Pošaljite sliku ili PDF do 10 MB.'], 413);
}
if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] <= 0) {
  json_response(['ok' => false, 'error' => 'Dokaz nije uspešno otpremljen. Pokušajte ponovo.'], 400);
}
$type = detect_payment_proof_type((string)$file['tmp_name']);
if ($type === null) {
  json_response(['ok' => false, 'error' => 'Dokaz mora biti slika (JPG, PNG, WEBP, HEIC) ili PDF.'], 415);
}
[$proofMime, $proofExt] = $type;
$originalName = trim((string)preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename((string)($file['name'] ?? ''))));
$originalName = function_exists('mb_substr') ? mb_substr($originalName, 0, 200, 'UTF-8') : substr($originalName, 0, 200);
if ($originalName === '') $originalName = 'dokaz.' . $proofExt;

$storedPath = null;
try {
  $pdo = db();
  ensure_payment_proof_columns($pdo);
  require_completed_profile($pdo, (int)$reseller['id']);
  enforce_rate_limit(
    $pdo,
    'payment_notice',
    'reseller:' . (int)$reseller['id'],
    10,
    3600,
    'Poslali ste previše prijava uplate. Sačekajte malo i pokušajte ponovo.'
  );

  $stmt = $pdo->prepare('SELECT id, email, balance_rsd FROM resellers WHERE id = ? LIMIT 1');
  $stmt->execute([$reseller['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    json_response(['ok' => false, 'error' => 'Nalog nije pronađen. Refrešujte stranicu i ulogujte se ponovo.'], 404);
  }
  $profile = reseller_profile($pdo, (int)$row['id']) ?: [];

  $relative = gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $proofExt;
  $target = payment_proof_dir() . '/' . $relative;
  if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
    throw new RuntimeException('proof_dir_unavailable');
  }
  if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
    throw new RuntimeException('proof_move_failed');
  }
  $storedPath = $target;
  @chmod($target, 0640);

  $recipients = notification_recipients('mail.payment_notice_to', [
    'arsenijee19@gmail.com',
    'support@licenca.rs',
  ]);

  $insert = $pdo->prepare('INSERT INTO payment_notice_requests (reseller_id, reseller_email, balance_rsd, clicked_at, status, recipients, attempts, amount_rsd, review_status, proof_file, proof_mime, proof_size, proof_name) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)');
  $insert->execute([
    (int)$row['id'],
    (string)$row['email'],
    (int)$row['balance_rsd'],
    gmdate('Y-m-d H:i:s'),
    'pending',
    implode(', ', $recipients),
    $amount,
    'pending',
    $relative,
    $proofMime,
    (int)$file['size'],
    $originalName,
  ]);
  $noticeId = (int)$pdo->lastInsertId();
  record_login_attempt($pdo, 'payment_notice', 'reseller:' . (int)$row['id'], true);
} catch (Throwable $e) {
  if ($storedPath !== null) @unlink($storedPath);
  error_log('payment_notice_failed class=' . get_class($e) . ' message=' . $e->getMessage());
  json_response(['ok' => false, 'error' => 'Prijava uplate trenutno nije sačuvana. Pokušajte ponovo.'], 500);
}

// The notice and receipt are stored, so answer now and send the email afterwards;
// SMTP latency used to make the "Uplatio sam" button feel stuck.
$earlyResponse = json_encode([
  'ok' => true,
  'notice_id' => $noticeId,
  'notice_recorded' => true,
  'message' => 'Uplata je poslata na proveru. Saldo će biti uvećan čim je admin odobri.',
  'csrf_token' => csrf_token(),
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

try {
  $result = send_text_notification_email($recipients, 'Reseller je označio uplatu i priložio dokaz', payment_notice_email_body([
    'id' => $noticeId,
    'reseller_id' => (int)$row['id'],
    'reseller_email' => (string)$row['email'],
    'reseller_name' => (string)($profile['display_name'] ?? ''),
    'balance_rsd' => (int)$row['balance_rsd'],
    'amount_rsd' => $amount,
    'proof_name' => $originalName,
    'proof_mime' => $proofMime,
    'proof_size' => (int)$file['size'],
    'clicked_at' => gmdate('Y-m-d H:i:s'),
  ]));
  $update = $pdo->prepare('UPDATE payment_notice_requests SET status = ?, attempts = attempts + 1, error_message = ?, updated_at = NOW() WHERE id = ?');
  $update->execute([
    $result['ok'] ? 'sent' : 'failed',
    $result['ok'] ? null : safe_public_error((string)$result['error']),
    $noticeId,
  ]);
  audit_event($pdo, 'reseller', (int)$row['id'], 'payment_notice_created', $result['ok'] ? 'success' : 'notification_failed', [
    'notice_id' => $noticeId,
    'amount_rsd' => $amount,
    'notification_sent' => $result['ok'],
  ]);
} catch (Throwable $e) {
  error_log('payment_notice_deferred_failed class=' . get_class($e));
}
exit;

function ini_parse_quantity_safe(string $value): int {
  $value = trim($value);
  if ($value === '') return 0;
  $unit = strtolower(substr($value, -1));
  $number = (int)$value;
  switch ($unit) {
    case 'g': return $number * 1073741824;
    case 'm': return $number * 1048576;
    case 'k': return $number * 1024;
    default: return (int)$value;
  }
}
