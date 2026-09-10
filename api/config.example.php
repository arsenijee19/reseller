<?php
declare(strict_types=1);

return [
  'app' => [
    'timezone' => 'Europe/Belgrade',
  ],
  'db' => [
    'host' => 'localhost',
    'name' => 'database_name',
    'user' => 'database_user',
    'pass' => 'database_password',
    'charset' => 'utf8mb4',
  ],
  'admin' => [
    'username' => 'admin',
    'password_hash' => 'GENERATE_WITH_password_hash_DO_NOT_COMMIT_REAL_HASH',
  ],
  'security' => [
    'encryption_key' => 'GENERATE_LONG_RANDOM_VALUE_DO_NOT_COMMIT_REAL_KEY',
    'origin' => 'https://reseller.psigre.rs',
    'webauthn' => [
      'rp_id' => 'reseller.psigre.rs',
      'origin' => 'https://reseller.psigre.rs',
      'rp_name' => 'PlayWorld.rs Admin',
    ],
  ],
  'mail' => [
    'from' => 'no-reply@playworld.rs',
    'order_to' => 'arsenijee19@gmail.com,support@licenca.rs',
    'payment_notice_to' => 'arsenijee19@gmail.com,support@licenca.rs',
  ],
  'integrations' => [
    'n8n_webhook' => '',
  ],
  'inventory' => [
    'api_base' => 'https://baza.igreps.rs',
    'supplier_token' => '',
  ],
];
