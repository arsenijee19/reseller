<?php
declare(strict_types=1);

return [
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
  'mail' => [
    'from' => 'no-reply@example.com',
    'order_to' => 'admin@example.com',
    'payment_notice_to' => 'admin@example.com',
  ],
  'integrations' => [
    'n8n_webhook' => '',
  ],
];
