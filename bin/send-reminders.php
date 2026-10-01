<?php

declare(strict_types=1);

/**
 * Daily scheduled task: deadline reminders, retention rules, housekeeping.
 *
 * Usage: php bin/send-reminders.php [--dry-run] [--date=YYYY-MM-DD] [--only=reminders,retention,housekeeping]
 *
 *   --dry-run   compute and print what would be done, change nothing
 *   --date      run as if today were this date (catch-up after an outage)
 *   --only      run some parts only
 *
 * Exit codes: 0 success, 1 failure (details on stderr), 2 another run is in progress, 64 bad usage.
 * Safe to run several times a day: notifications are deduplicated.
 * See docs/scheduled-task-plesk.md.
 */

use App\Core\Env;
use App\Core\Kernel;
use App\Domain\SiteScope;
use App\Services\DailyTaskService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['dry-run', 'date:', 'only:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/send-reminders.php [--dry-run] [--date=YYYY-MM-DD] [--only=reminders,retention,housekeeping]\n");
    exit(0);
}
$dryRun = isset($options['dry-run']);
$date = isset($options['date']) && is_string($options['date']) ? $options['date'] : null;
if ($date !== null && (DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false || DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date)) {
    fwrite(STDERR, "Invalid --date (expected YYYY-MM-DD): {$date}\n");
    exit(64);
}
$parts = DailyTaskService::PARTS;
if (isset($options['only']) && is_string($options['only'])) {
    $parts = array_values(array_intersect(DailyTaskService::PARTS, array_map('trim', explode(',', $options['only']))));
    if ($parts === []) {
        fwrite(STDERR, 'Invalid --only (expected: ' . implode(',', DailyTaskService::PARTS) . ")\n");
        exit(64);
    }
}

// .env when present; otherwise the process environment only (e.g. variables set in Plesk).
$env = is_file($root . '/.env') ? Env::load($root . '/.env') : new Env();
$container = Kernel::boot($root, $env)->container();
// Scheduled task: no logged-in user, every site.
$container->instance(SiteScope::class, SiteScope::system());

// One run at a time (Plesk may start a new one while a long run is still going).
$lockDir = rtrim($env->get('STORAGE_PATH') ?: $root . '/storage', '/\\') . '/locks';
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Cannot create lock directory {$lockDir}\n");
    exit(1);
}
$lock = fopen($lockDir . '/send-reminders.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another run of send-reminders is in progress.\n");
    exit(2);
}

try {
    $summary = $container->get(DailyTaskService::class)->run($dryRun, $date, $parts);
} catch (Throwable $e) {
    fwrite(STDERR, 'send-reminders failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

$lines = [sprintf('[%s] send-reminders %s%s', date('Y-m-d H:i:s'), $summary['today'], $dryRun ? ' (dry run: nothing changed)' : '')];
if (isset($summary['reminders'])) {
    $r = $summary['reminders'];
    $lines[] = sprintf('  reminders: %d mail(s) due, %d overdue, %d due soon; %d notification(s) created, %d already sent',
        $r['mails'], $r['overdue'], $r['due_soon'], $r['created'], $r['already_sent']);
}
if (isset($summary['retention'])) {
    $r = $summary['retention'];
    $lines[] = sprintf('  retention: %d archived, %d file(s) purged on %d mail(s), %d review notification(s), %d error(s)%s',
        $r['archived'], $r['purged_files'], $r['purged_mails'], $r['review_notifications'], $r['errors'],
        $r['limited'] ? ' (limit reached: the rest is done on the next run)' : '');
}
if (isset($summary['housekeeping'])) {
    $h = $summary['housekeeping'];
    $lines[] = sprintf('  housekeeping: %d old notification(s), %d old login attempt(s) deleted', $h['notifications'], $h['login_attempts']);
}
fwrite(STDOUT, implode("\n", $lines) . "\n");

if (!$summary['ok']) {
    fwrite(STDERR, 'send-reminders finished with errors' . (isset($summary['error']) ? ': ' . $summary['error'] : '') . "\n");
    exit(1);
}
exit(0);
