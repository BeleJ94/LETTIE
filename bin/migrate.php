<?php

declare(strict_types=1);

/**
 * Usage: php bin/migrate.php [--status]
 */

use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$env = Env::load($root . '/.env');
$migrator = new Migrator(Database::connect($env), $root . '/database/migrations');

if (in_array('--status', $argv, true)) {
    $applied = $migrator->applied();
    foreach ($migrator->files() as $file) {
        echo (in_array($file, $applied, true) ? '[x] ' : '[ ] ') . $file . PHP_EOL;
    }
    exit(0);
}

try {
    $done = $migrator->migrate(static function (string $file): void {
        echo "Applied: {$file}" . PHP_EOL;
    });
    echo $done === [] ? 'Nothing to migrate.' . PHP_EOL : count($done) . ' migration(s) applied.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
