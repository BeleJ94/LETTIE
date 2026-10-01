<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

final class MigratorTest extends TestCase
{
    public function testAllMigrationsAppliedAndIdempotent(): void
    {
        $pdo = TestDatabase::pdo();
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');

        self::assertSame($migrator->files(), $migrator->applied());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate());
        self::assertSame(5, (int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn());
    }

    public function testSplitStatements(): void
    {
        $sql = "-- comment; ignored\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1),\n (2);\n";
        self::assertSame(['CREATE TABLE a (x INT)', "INSERT INTO a VALUES (1),\n (2)"], Migrator::splitStatements($sql));
    }
}
