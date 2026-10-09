<?php
declare(strict_types=1);

require __DIR__ . '/../api/device_auth_helpers.php';

function expect(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$seen = [];
for ($i = 0; $i < 1000; $i++) {
  $code = app_activation_code();
  expect((bool)preg_match('/^[A-HJ-NP-Z2-9]{12}$/', $code), 'Activation code has invalid format.');
  expect(normalize_app_activation_code($code) === $code, 'Generated activation code did not normalize.');
  $seen[$code] = true;
}
expect(count($seen) === 1000, 'Unexpected duplicate in generated activation-code sample.');
expect(normalize_app_activation_code(' abcd-efgh-jkmn ') === 'ABCDEFGHJKMN', 'Code normalization failed.');
expect(normalize_app_activation_code('ABCDEFGHJKLM0') === '', 'Ambiguous/invalid code character was accepted.');
expect(normalize_app_activation_code('short') === '', 'Short activation code was accepted.');

$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
expect((bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $secret), 'Device credential has invalid format.');
expect(strlen(app_device_credential_hash($secret)) === 64, 'Device credential digest has invalid length.');
expect(app_device_credential_hash($secret) !== $secret, 'Device credential was not hashed.');

echo "device-auth-helpers-ok\n";
