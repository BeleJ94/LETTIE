<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (dev only: screenshots and user guide).
 *   php -S 127.0.0.1:8765 -t public tools/demo/router.php
 * Static files are served as-is; everything else goes to public/index.php.
 */
$public = dirname(__DIR__, 2) . '/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path !== '/' && !str_contains($path, '..') && is_file($public . $path)) {
    return false;
}
require $public . '/index.php';
