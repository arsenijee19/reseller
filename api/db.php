<?php
declare(strict_types=1);

function app_config(): array {
  static $config = null;
  if ($config !== null) return $config;

  $localConfig = __DIR__ . '/config.local.php';
  $config = is_file($localConfig) ? require $localConfig : [];
  return is_array($config) ? $config : [];
}

function config_value(string $path, mixed $default = null): mixed {
  $value = app_config();
  foreach (explode('.', $path) as $part) {
    if (!is_array($value) || !array_key_exists($part, $value)) {
      $envKey = strtoupper(str_replace('.', '_', $path));
      $env = getenv($envKey);
      return $env === false ? $default : $env;
    }
    $value = $value[$part];
  }
  return $value;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $host = (string)config_value('db.host', '');
  $db   = (string)config_value('db.name', '');
  $user = (string)config_value('db.user', '');
  $pass = (string)config_value('db.pass', '');
  $charset = (string)config_value('db.charset', 'utf8mb4');

  if ($host === '' || $db === '' || $user === '') {
    throw new RuntimeException('Database configuration is missing.');
  }

  $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}
