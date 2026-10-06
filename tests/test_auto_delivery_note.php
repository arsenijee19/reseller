<?php
declare(strict_types=1);

require __DIR__ . '/../api/auto_delivery_note_helpers.php';

function check(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$original = 'Kupac Jovan; uplata evidentirana.';
$first = append_auto_delivery_note($original, 'ztqhmuew8777@hotmail.com');
check($first['notes'] === $original . "\nLogin mail (automatska isporuka): ztqhmuew8777@hotmail.com", 'Existing notes must be preserved.');
check(!$first['already_recorded'], 'The first delivery note must be new.');

$duplicate = append_auto_delivery_note($first['notes'], 'ztqhmuew8777@hotmail.com');
check($duplicate['already_recorded'] && $duplicate['same_email'], 'A retry with the same email must be idempotent.');
check($duplicate['notes'] === $first['notes'], 'An idempotent retry must not append again.');

$conflict = append_auto_delivery_note($first['notes'], 'other@example.com');
check($conflict['already_recorded'] && !$conflict['same_email'], 'A different email must not overwrite the recorded one.');

$invalidRejected = false;
try {
  append_auto_delivery_note('', 'not-an-email');
} catch (InvalidArgumentException $e) {
  $invalidRejected = true;
}
check($invalidRejected, 'Invalid login email must be rejected.');

$tooLongRejected = false;
try {
  append_auto_delivery_note(str_repeat('a', 2000), 'valid@example.com');
} catch (LengthException $e) {
  $tooLongRejected = true;
}
check($tooLongRejected, 'A note must not exceed the reseller notes limit.');

check(delivery_callback_message(17, 24, str_repeat('a', 32), 123) === "17\n24\n" . str_repeat('a', 32) . "\n123", 'Callback signature payload must be deterministic.');

echo "auto-delivery-note-ok\n";
