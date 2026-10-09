<?php
declare(strict_types=1);

const APP_ACTIVATION_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function app_activation_code(): string {
  $bytes = random_bytes(12);
  $alphabet = APP_ACTIVATION_CODE_ALPHABET;
  $code = '';
  for ($i = 0; $i < 12; $i++) {
    $code .= $alphabet[ord($bytes[$i]) & 31];
  }
  return $code;
}

function normalize_app_activation_code(string $code): string {
  $code = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
  return preg_match('/^[A-HJ-NP-Z2-9]{12}$/', $code) ? $code : '';
}

function app_device_credential_hash(string $credential): string {
  return hash('sha256', $credential);
}
