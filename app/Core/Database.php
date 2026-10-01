<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    public static function dsn(Env $env): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $env->get('DB_HOST', '127.0.0.1'),
            $env->int('DB_PORT', 3306),
            $env->require('DB_NAME'),
        );
    }

    /** @return array<int, mixed> */
    public static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            // Strict mode whatever the server default: too-long values or invalid dates raise errors
            // instead of being silently truncated or zeroed.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
                . "sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'",
        ];
    }

    public static function connect(Env $env): PDO
    {
        return new PDO(
            self::dsn($env),
            $env->require('DB_USER'),
            $env->get('DB_PASSWORD', ''),
            self::options(),
        );
    }
}
