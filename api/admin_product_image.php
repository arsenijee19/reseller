<?php
declare(strict_types=1);

// Admin: set or remove the cover image of one or more products (all variants of a game at once).
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
start_secure_session('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
require_same_origin();
$admin = require_admin();
require_csrf();

$productIds = array_values(array_filter(array_map('strval', (array)($_POST['product_ids'] ?? [])), static fn($id) => $id !== '' && strlen($id) <= 64));
if (!$productIds || count($productIds) > 50) {
  json_response(['ok' => false, 'error' => 'Izaberite proizvod.'], 400);
}

try {
  $pdo = db();
  if (!ensure_product_image_column($pdo)) {
    json_response(['ok' => false, 'error' => 'Kolona za slike nije dostupna u bazi.'], 503);
  }
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $old = $pdo->prepare("SELECT image_file FROM product_prices WHERE product_id IN ({$placeholders})");
  $old->execute($productIds);
  $oldFiles = $old->fetchAll(PDO::FETCH_COLUMN);

  if (($_POST['action'] ?? 'upload') === 'remove') {
    $pdo->prepare("UPDATE product_prices SET image_file = NULL WHERE product_id IN ({$placeholders})")->execute($productIds);
    prune_product_images($pdo, $oldFiles);
    audit_event($pdo, 'admin', (int)$admin['id'], 'product_image_removed', 'success', ['product_ids' => $productIds]);
    json_response(['ok' => true, 'image_url' => '', 'product_ids' => $productIds]);
  }

  $upload = $_FILES['image'] ?? null;
  if (!is_array($upload) || is_array($upload['error'] ?? null) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    json_response(['ok' => false, 'error' => 'Izaberite sliku.'], 400);
  }
  if (in_array((int)$upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int)$upload['size'] > PRODUCT_IMAGE_MAX_BYTES) {
    json_response(['ok' => false, 'error' => 'Slika je prevelika (najviše 8 MB).'], 413);
  }
  if ((int)$upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
    json_response(['ok' => false, 'error' => 'Slika nije otpremljena. Pokušajte ponovo.'], 400);
  }

  try {
    $file = store_product_image((string)$upload['tmp_name']);
  } catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 415);
  }
  $pdo->prepare("UPDATE product_prices SET image_file = ? WHERE product_id IN ({$placeholders})")->execute(array_merge([$file], $productIds));
  prune_product_images($pdo, $oldFiles);
  audit_event($pdo, 'admin', (int)$admin['id'], 'product_image_updated', 'success', ['product_ids' => $productIds, 'file' => $file]);
  json_response(['ok' => true, 'image_url' => product_image_url($file), 'product_ids' => $productIds, 'csrf_token' => csrf_token()]);
} catch (Throwable $e) {
  error_log('product_image_failed class=' . get_class($e));
  json_response(['ok' => false, 'error' => 'Slika trenutno nije sačuvana. Pokušajte ponovo.'], 500);
}
