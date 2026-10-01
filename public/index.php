<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Kernel;
use App\Core\Request;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$env = Env::load($root . '/.env');
$kernel = Kernel::boot($root, $env);

$kernel->handle(Request::fromGlobals($env->get('APP_BASE_PATH', '') ?? ''))->send();
