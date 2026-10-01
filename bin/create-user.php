<?php

declare(strict_types=1);

/**
 * Usage:
 *   php bin/create-user.php --email=a@b.fr --role=admin --site=HQ [--site-name="Siège"] \
 *       --first-name=Ana --last-name=Martin
 * The password is read from standard input.
 */

use App\Core\Kernel;
use App\Domain\Auth\Role;
use App\Domain\SiteScope;
use App\Services\UserService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['email:', 'role:', 'site:', 'site-name::', 'first-name:', 'last-name:']);
foreach (['email', 'role', 'site', 'first-name', 'last-name'] as $required) {
    if (!isset($options[$required]) || !is_string($options[$required])) {
        fwrite(STDERR, "Missing --{$required}" . PHP_EOL);
        exit(1);
    }
}
$role = Role::tryFrom($options['role']);
if ($role === null) {
    fwrite(STDERR, 'Role must be one of: ' . implode(', ', array_column(Role::cases(), 'value')) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Password: ');
$password = rtrim((string) fgets(STDIN), "\r\n");
// Windows PowerShell prefixes piped text with a UTF-8 BOM: it is not part of the password.
if (str_starts_with($password, "\xEF\xBB\xBF")) {
    $password = substr($password, 3);
}

$container = Kernel::boot($root)->container();
// Command-line administration: no logged-in user, unrestricted scope stated explicitly.
$container->instance(SiteScope::class, SiteScope::system());

try {
    $id = $container->get(UserService::class)->create(
        $options['site'],
        isset($options['site-name']) && is_string($options['site-name']) ? $options['site-name'] : null,
        $role,
        $options['email'],
        $password,
        $options['first-name'],
        $options['last-name'],
    );
    echo "User #{$id} created." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
