<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

echo json_encode([
  'ok' => true,
  'version' => '2026-10-09-app-install',
], JSON_UNESCAPED_SLASHES);
