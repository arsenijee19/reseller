<?php
declare(strict_types=1);

function delivery_callback_message(int $orderId, int $resellerId, string $requestId, int $issuedAt): string {
  return implode("\n", [$orderId, $resellerId, $requestId, $issuedAt]);
}

function append_auto_delivery_note(string $notes, string $loginEmail): array {
  $loginEmail = trim($loginEmail);
  if (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL) || strlen($loginEmail) > 254) {
    throw new InvalidArgumentException('Login email nije validan.');
  }

  $marker = 'Login mail (automatska isporuka):';
  if (preg_match('/(?:^|\n)' . preg_quote($marker, '/') . '\s*([^\r\n]*)/u', $notes, $match)) {
    return [
      'notes' => $notes,
      'already_recorded' => true,
      'same_email' => strcasecmp(trim($match[1]), $loginEmail) === 0,
    ];
  }

  $line = $marker . ' ' . $loginEmail;
  $updated = trim($notes) === '' ? $line : rtrim($notes) . "\n" . $line;
  if (function_exists('mb_strlen') ? mb_strlen($updated, 'UTF-8') > 2000 : strlen($updated) > 2000) {
    throw new LengthException('Beleška bi premašila dozvoljenu dužinu.');
  }

  return ['notes' => $updated, 'already_recorded' => false, 'same_email' => false];
}
