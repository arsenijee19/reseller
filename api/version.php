<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

// Android app release info, written by the Android workflow next to the published APK.
$android = ['code' => 0, 'name' => '', 'min_code' => 0, 'stable_key' => false, 'apk_url' => '/downloads/PlayWorld-Reseller.apk'];
$file = __DIR__ . '/../downloads/app-version.json';
if (is_file($file)) {
  $data = json_decode((string)file_get_contents($file), true);
  $info = is_array($data) && is_array($data['android'] ?? null) ? $data['android'] : [];
  $android['code'] = (int)($info['code'] ?? 0);
  $android['name'] = substr((string)($info['name'] ?? ''), 0, 32);
  $android['min_code'] = (int)($info['min_code'] ?? 0);
  $android['stable_key'] = !empty($info['stable_key']);
}

echo json_encode([
  'ok' => true,
  'version' => '2026-10-10-login-calm',
  'android' => $android,
], JSON_UNESCAPED_SLASHES);
