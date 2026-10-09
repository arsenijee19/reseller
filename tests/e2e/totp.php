<?php
// Test helper: php totp.php <www-dir> enable admin|reseller <id>   -> prints the base32 secret
//               php totp.php <www-dir> code <base32-secret>         -> prints the current 6-digit code
declare(strict_types=1);
[$self, $www, $cmd] = $argv;
require $www . '/api/bootstrap.php';
if ($cmd === 'code') { echo hotp_code($argv[3], intdiv(time(), 30)); exit; }
$who = $argv[3]; $id = (int)$argv[4];
$pdo = db(); ensure_security_tables($pdo);
$secret = generate_totp_secret();
if ($who === 'admin') {
  $pdo->prepare('INSERT INTO admin_two_factor (admin_id, secret_encrypted, enabled_at) VALUES (?, ?, NOW())')->execute([$id, encrypt_secret($secret)]);
} else {
  $pdo->prepare('INSERT INTO reseller_two_factor (reseller_id, secret_encrypted, enabled_at) VALUES (?, ?, NOW())')->execute([$id, encrypt_secret($secret)]);
}
echo $secret;
