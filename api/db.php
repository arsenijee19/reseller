<?php
declare(strict_types=1);

function app_config(): array {
  static $config = null;
  if ($config !== null) return $config;

  $localConfig = __DIR__ . '/config.local.php';
  $config = is_file($localConfig) ? require $localConfig : [];
  return is_array($config) ? $config : [];
}

function app_config_status(): array {
  $localConfig = __DIR__ . '/config.local.php';
  $config = app_config();

  return [
    'config_exists' => is_file($localConfig),
    'config_readable' => is_readable($localConfig),
    'db_host_set' => (string)($config['db']['host'] ?? getenv('DB_HOST') ?: '') !== '',
    'db_name_set' => (string)($config['db']['name'] ?? getenv('DB_NAME') ?: '') !== '',
    'db_user_set' => (string)($config['db']['user'] ?? getenv('DB_USER') ?: '') !== '',
    'db_pass_set' => (string)($config['db']['pass'] ?? getenv('DB_PASS') ?: '') !== '',
  ];
}

function public_error_detail(Throwable $e): string {
  $message = $e->getMessage();

  if ($e instanceof PDOException) {
    if (strpos($message, '[1045]') !== false) return 'MySQL odbija pristup. Proveri DB user/password i privilegije.';
    if (strpos($message, '[1049]') !== false) return 'MySQL baza ne postoji ili DB name nije tačan.';
    if (strpos($message, '[2002]') !== false) return 'MySQL host nije dostupan. Proveri DB host.';
    if (strpos($message, 'Base table or view not found') !== false) return 'Baza radi, ali jedna od potrebnih tabela ne postoji.';
    return 'PDO greška pri konekciji ili upitu. Proveri cPanel MySQL podešavanja.';
  }

  if (strpos($message, 'Database configuration is missing') !== false) {
    $status = app_config_status();
    $missing = [];
    foreach ($status as $key => $ok) {
      if (!$ok) $missing[] = $key;
    }
    return 'DB konfiguracija nije kompletna: ' . implode(', ', $missing);
  }

  return 'Neočekivana server greška u login toku.';
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
