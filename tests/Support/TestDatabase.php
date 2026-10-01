<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Recreates the TEST_DB_NAME database once per run, applies the migrations,
 * and truncates data tables between tests. Skips when no server is reachable.
 */
final class TestDatabase
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $name = (string) getenv('TEST_DB_NAME');
        if (!preg_match('/^[a-z0-9_]+_test$/', $name)) {
            throw new RuntimeException('TEST_DB_NAME must end with "_test".');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', getenv('TEST_DB_HOST'), (int) getenv('TEST_DB_PORT'));

        try {
            $pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), Database::options());
        } catch (PDOException $e) {
            Assert::markTestSkipped('MariaDB not available: ' . $e->getMessage());
        }

        $pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$name}`");
        (new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        return self::$pdo = $pdo;
    }

    public static function reset(): PDO
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['scheduled_runs', 'notifications', 'retention_rules', 'activity_log', 'mail_links', 'annotations', 'assignments', 'delegations', 'attachments', 'mails', 'mail_sequences', 'correspondents', 'login_attempts', 'users', 'departments', 'sites'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $pdo;
    }

    public static function insertSite(string $code): int
    {
        $stmt = self::pdo()->prepare('INSERT INTO sites (code, name) VALUES (:code, :name)');
        $stmt->execute(['code' => $code, 'name' => 'Site ' . $code]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function insertDepartment(int $siteId, string $code): int
    {
        $stmt = self::pdo()->prepare('INSERT INTO departments (site_id, code, name) VALUES (:site, :code, :name)');
        $stmt->execute(['site' => $siteId, 'code' => $code, 'name' => 'Dept ' . $code]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function insertCorrespondent(int $siteId, string $name, bool $active = true): int
    {
        $stmt = self::pdo()->prepare('INSERT INTO correspondents (site_id, type, name, is_active) VALUES (:site, \'organization\', :name, :active)');
        $stmt->execute(['site' => $siteId, 'name' => $name, 'active' => $active ? 1 : 0]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function insertUser(int $siteId, string $email, string $password, string $role = 'agent', bool $active = true): int
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO users (site_id, role_id, email, password_hash, first_name, last_name, is_active)
             SELECT :site, id, :email, :hash, \'Test\', \'User\', :active FROM roles WHERE code = :role'
        );
        $stmt->execute([
            'site' => $siteId,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => $active ? 1 : 0,
            'role' => $role,
        ]);
        return (int) self::pdo()->lastInsertId();
    }
}
