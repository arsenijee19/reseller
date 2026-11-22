<?php
declare(strict_types=1);

// Weekly "stanje naloga" reminder for resellers in minus.
// cPanel → Cron Jobs, every hour:
//   0 * * * * /usr/local/bin/php /home/psigrersrs/reseller.psigre.rs/api/cron_balance_reminder.php >/dev/null 2>&1
// It only sends on Sunday between 14:00 and 14:59 Belgrade time, once per week (whatever the server's
// timezone is). "--force" sends now (still only once per reseller per week).

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Forbidden\n";
  exit;
}

require __DIR__ . '/bootstrap.php';

$force = in_array('--force', $argv ?? [], true);
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Belgrade'));
if (!$force && !($now->format('N') === '7' && $now->format('G') === '14')) {
  echo 'Not the time (' . $now->format('D H:i') . " Europe/Belgrade), nothing sent.\n";
  exit(0);
}

$settings = balance_reminder_settings();
if (!$settings['enabled'] && !$force) {
  echo "Weekly reminder is turned off in the admin panel.\n";
  exit(0);
}

try {
  $pdo = db();
  $result = run_balance_reminders($pdo, 'week-' . $now->format('o-\WW'));
  echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
  try {
    audit_event($pdo, 'system', null, 'balance_reminder_run', $result['failed'] > 0 ? 'partial' : 'success', $result);
  } catch (Throwable $ignored) {}
} catch (Throwable $e) {
  fwrite(STDERR, 'balance reminder failed: ' . get_class($e) . "\n");
  exit(1);
}
