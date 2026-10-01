<?php

declare(strict_types=1);

/**
 * Demo / test data (dev only).
 *
 *   php tools/demo/seed.php [--size=small|medium|large] [--seed=2026] [--skip-checks]
 *
 *   small   ~10 months simulated through the services (screenshots, user guide)
 *   medium  2 years through the services (pagination, exports, filters)
 *   large   3 years of bulk SQL history (~100,000 mails) + 2 months through the services (performance)
 *
 * Proportions: tools/demo/profile.php. Same seed → same data.
 * Recreates DEMO_DB_NAME (default lettie_demo, must end with "_demo"); never touches the .env database.
 * Ends with checks (invariants, edge cases, proportions, timings); exit code 1 if an invariant fails.
 * Writes tools/demo/demo.json (accounts and ids used by the screenshots).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Core\Env;
use App\Core\FileStorage;
use App\Core\Kernel;
use App\Core\Migrator;
use App\Domain\SiteScope;
use App\Services\DailyTaskService;
use App\Services\StatsService;
use Tools\Demo\BulkWriter;
use Tools\Demo\Checks;
use Tools\Demo\DemoClock;
use Tools\Demo\EdgeCases;
use Tools\Demo\Generator;
use Tools\Demo\Organisation;
use Tools\Demo\Random;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
date_default_timezone_set('Europe/Paris');

$options = getopt('', ['size:', 'seed:', 'skip-checks', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/demo/seed.php [--size=small|medium|large] [--seed=N] [--skip-checks]\n";
    exit(0);
}
$profile = require __DIR__ . '/profile.php';
$sizeName = (string) ($options['size'] ?? 'small');
if (!isset($profile['sizes'][$sizeName])) {
    fwrite(STDERR, "Unknown size {$sizeName} (small, medium, large).\n");
    exit(64);
}
$size = $profile['sizes'][$sizeName];
$seed = (int) ($options['seed'] ?? 2026);
$started = hrtime(true);
$say = static fn (string $line) => fwrite(STDOUT, $line . "\n");

$dbName = getenv('DEMO_DB_NAME') ?: 'lettie_demo';
if (!preg_match('/^[a-z0-9_]+_demo$/', $dbName)) {
    fwrite(STDERR, "DEMO_DB_NAME must end with _demo (got {$dbName}).\n");
    exit(1);
}
$storage = getenv('DEMO_STORAGE_PATH') ?: $root . '/storage/demo';

// Same server as .env, demo database. Real environment variables override .env values.
putenv("DB_NAME={$dbName}");
putenv("STORAGE_PATH={$storage}");
putenv('APP_TIMEZONE=Europe/Paris');
$env = is_file($root . '/.env') ? Env::load($root . '/.env') : new Env();

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $env->get('DB_HOST', '127.0.0.1'), $env->int('DB_PORT', 3306)),
    $env->require('DB_USER'),
    $env->get('DB_PASSWORD', ''),
    Database::options(),
);
$server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = Database::connect($env);
(new Migrator($pdo, $root . '/database/migrations'))->migrate();
if (is_dir($storage)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$windowStart = $now->modify("-{$size['window_days']} days");
// The organisation exists before the first simulated (or bulk) mail.
$setupAt = $windowStart->modify('-' . ($size['bulk_days'] + 1) . ' days');
$clock = new DemoClock($setupAt);
$container = Kernel::boot($root, $env)->container();
$container->instance(PDO::class, $pdo);
$container->instance(Clock::class, $clock);
$container->instance(SiteScope::class, SiteScope::system());
$container->instance(FileStorage::class, new FileStorage($storage . '/attachments', uploadsOnly: false));

$say("Lettie demo data — size {$sizeName}, seed {$seed}, database {$dbName}");
$random = new Random($seed);
$org = Organisation::create($container, $pdo, $clock, $profile);

if ($size['bulk_mails'] > 0) {
    $say("• Bulk history: {$size['bulk_mails']} mails over {$size['bulk_days']} days");
    $written = (new BulkWriter($pdo, $org, $random, $profile))->write($windowStart->modify("-{$size['bulk_days']} days"), $windowStart, $size['bulk_mails'], $say);
    $say("  {$written} mails written");
}

$say("• Simulation through the services: {$size['window_days']} days");
$generator = new Generator($container, $org, $clock, $random, $profile, $say);
$cases = new EdgeCases($generator, $container, $pdo, $org);
$cases->register($windowStart, $now);
$generator->run($windowStart, $now, $size['daily_mean']);
$cases->finalize($now);

// created_at is filled by the database at insert time: align it with the simulated dates.
$pdo->exec('UPDATE mails SET created_at = COALESCE(received_at, sent_at, created_at)');

$say('• Daily task (reminders, retention)');
$clock->reset();
$daily = $container->get(DailyTaskService::class)->run();

$failed = false;
if (!isset($options['skip-checks'])) {
    $checks = new Checks($pdo, $container->get(FileStorage::class));
    $say('• Invariants');
    foreach ($checks->invariants() as [$name, $ok, $detail]) {
        $say(sprintf('  %s %s%s', $ok ? '✓' : '✗', $name, $ok || $detail === '' ? '' : " — {$detail}"));
        $failed = $failed || !$ok;
    }
    $say('• Edge cases');
    foreach ($checks->edgeCases($cases->expectations()) as [$name, $ok, $detail]) {
        $say(sprintf('  %s %s — %s', $ok ? '✓' : '✗', $name, $detail));
        $failed = $failed || !$ok;
    }
    foreach ($cases->skipped as $skip) {
        $say("  - skipped: {$skip}");
    }
    $say('• Proportions vs profile');
    $warnings = $checks->proportions($profile);
    $say($warnings === [] ? '  ✓ within tolerance' : '  ! ' . implode("\n  ! ", $warnings));
    if ($sizeName !== 'small') {
        $say('• Timings');
        foreach ($checks->timings($container->get(StatsService::class), $container->get(DailyTaskService::class)) as $what => $ms) {
            $say(sprintf('  %-32s %8.1f ms', $what, $ms));
        }
    }
    $say('• Summary');
    foreach ($checks->summary() as $label => $value) {
        $say(sprintf('  %-22s %s', $label, $value));
    }
}

$stats = $generator->stats;
$say(sprintf('  %-22s %s', 'simulated actions', "{$stats['incoming']} incoming, {$stats['outgoing']} outgoing, {$stats['replies']} replies, {$stats['closed']} closures, {$stats['reassigned']} reassignments, {$stats['skipped_actions']} skipped"));

$accounts = [];
foreach ($profile['users'] as $key => [$login]) {
    $accounts[$key] = "{$login}@{$profile['email_domain']}";
}
file_put_contents(__DIR__ . '/demo.json', json_encode([
    'size' => $sizeName,
    'seed' => $seed,
    'password' => $profile['password'],
    'accounts' => $accounts,
    'showcaseMailId' => $cases->ids['showcase'] ?? null,
    'cases' => $cases->ids,
    'mails' => (int) $pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn(),
    'database' => $dbName,
    'storage' => $storage,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$say(sprintf('%s in %.1f s — reminders: %d created.', $failed ? 'FAILED' : 'Done', (hrtime(true) - $started) / 1e9, $daily['reminders']['created'] ?? 0));
exit($failed ? 1 : 0);
