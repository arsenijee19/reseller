<?php
declare(strict_types=1);

function app_config(): array {
  static $config = null;
  if ($config !== null) return $config;

  $localConfig = __DIR__ . '/config.local.php';
  $config = is_file($localConfig) ? require $localConfig : [];
  return is_array($config) ? $config : [];
}

function app_config_path(): string {
  return __DIR__ . '/config.local.php';
}

function write_app_config(array $config): void {
  $path = app_config_path();
  $export = var_export($config, true);
  $content = "<?php\n";
  $content .= "declare(strict_types=1);\n\n";
  $content .= "return " . $export . ";\n";

  $dir = dirname($path);
  if (!is_writable($dir) && (!is_file($path) || !is_writable($path))) {
    throw new RuntimeException('api/config.local.php nije upisiv. Proverite file permissions na cPanelu.');
  }
  if (file_put_contents($path, $content, LOCK_EX) === false) {
    throw new RuntimeException('Ne mogu da upišem api/config.local.php.');
  }
  clearstatcache(true, $path);
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
    $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    if ($driverCode === 1062) return 'Reseller sa tim emailom već postoji. Osvežite listu i proverite nalog.';
    if ($driverCode === 1364 || $driverCode === 1048) return 'Bazi nedostaje obavezna vrednost za reseller nalog. Proverite da li je primenjena poslednja SQL migracija.';
    if ($driverCode === 1054 || $sqlState === '42S22') return 'Šema baze nije usklađena sa portalom. Potrebno je primeniti nedostajuću SQL migraciju.';
    if ($driverCode === 1146 || $sqlState === '42S02') return 'U bazi nedostaje potrebna tabela. Proverite SQL migracije.';
    if ($driverCode === 1452) return 'Baza je odbila povezani zapis. Proverite integritet podataka i SQL migracije.';
    if ($driverCode === 1142 || $driverCode === 1227) return 'MySQL korisniku nedostaje dozvola za ovu izmenu. Proverite privilegije baze na cPanelu.';
    if (strpos($message, '[1045]') !== false) return 'MySQL odbija pristup. Proveri DB user/password i privilegije.';
    if (strpos($message, '[1049]') !== false) return 'MySQL baza ne postoji ili DB name nije tačan.';
    if (strpos($message, '[2002]') !== false) return 'MySQL host nije dostupan. Proveri DB host.';
    if (strpos($message, 'Base table or view not found') !== false) return 'Baza radi, ali jedna od potrebnih tabela ne postoji.';
    return 'PDO greška pri konekciji ili upitu. Proveri cPanel MySQL podešavanja.';
  }

  if ($e instanceof TypeError && strpos($message, 'password_verify') !== false) {
    return 'Jedan aktivan reseller ima prazan ili neispravan token_hash u bazi.';
  }

  if ($e instanceof ParseError) return 'Server konfiguracija trenutno nije validna.';
  if (strpos($message, 'Database configuration is missing') !== false) return 'Server baza trenutno nije pravilno podešena.';
  return 'Server trenutno nije mogao da obradi zahtev.';
}

function log_api_failure(string $area, Throwable $e): string {
  $reference = bin2hex(random_bytes(4));
  $area = preg_match('/^[a-z0-9_-]{1,40}$/i', $area) ? $area : 'unknown';
  $sqlState = $e instanceof PDOException
    ? (string)($e->errorInfo[0] ?? $e->getCode())
    : (string)$e->getCode();
  $driverCode = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
  error_log(sprintf('API failure ref=%s area=%s type=%s sqlstate=%s driver_code=%d',
    $reference, $area, get_class($e), preg_replace('/[^A-Za-z0-9_-]/', '', $sqlState), $driverCode));
  return $reference;
}

function config_value(string $path, $default = null) {
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
