<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Database;
use App\Core\Env;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    public function testDsnUsesUtf8mb4(): void
    {
        $env = new Env(['DB_HOST' => 'db', 'DB_PORT' => '3307', 'DB_NAME' => 'lettie']);
        self::assertSame('mysql:host=db;port=3307;dbname=lettie;charset=utf8mb4', Database::dsn($env));
    }

    public function testOptionsDisableEmulatedPrepares(): void
    {
        $options = Database::options();
        self::assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function testDbNameIsRequired(): void
    {
        $this->expectException(RuntimeException::class);
        Database::dsn(new Env());
    }
}
