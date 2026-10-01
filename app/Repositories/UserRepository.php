<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class UserRepository extends Repository
{
    private const SELECT = 'SELECT u.id, u.site_id, u.department_id, r.code AS role_code, u.email, u.password_hash,
                                   u.first_name, u.last_name, u.locale, u.is_active, u.last_login_at
                            FROM users u
                            JOIN roles r ON r.id = u.role_id';

    public function findById(int $id): ?User
    {
        $params = ['id' => $id];
        $sql = self::SELECT . ' WHERE u.id = :id AND ' . $this->scopeSql('u.site_id', $params);
        return $this->fetchOne($sql, $params);
    }

    public function findByEmail(string $email): ?User
    {
        $params = ['email' => $email];
        $sql = self::SELECT . ' WHERE u.email = :email AND ' . $this->scopeSql('u.site_id', $params);
        return $this->fetchOne($sql, $params);
    }

    /**
     * Active users of the scope (assignment and delegation pickers).
     *
     * @return list<array{id: int, site_id: int, department_id: ?int, name: string, role: string}>
     */
    public function listActive(): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.site_id, u.department_id, CONCAT(u.first_name, ' ', u.last_name) AS name, r.code AS role
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND " . $this->scopeSql('u.site_id', $params) . ' ORDER BY u.last_name, u.first_name'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'site_id' => (int) $r['site_id'],
            'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
            'name' => (string) $r['name'],
            'role' => (string) $r['role'],
        ], $stmt->fetchAll());
    }

    public function create(
        int $siteId,
        ?int $departmentId,
        Role $role,
        string $email,
        string $passwordHash,
        string $firstName,
        string $lastName,
        string $locale = 'fr',
    ): int {
        $this->assertInScope($siteId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (site_id, department_id, role_id, email, password_hash, first_name, last_name, locale)
             SELECT :site_id, :department_id, r.id, :email, :password_hash, :first_name, :last_name, :locale
             FROM roles r WHERE r.code = :role'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'locale' => $locale,
            'role' => $role->value,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Role not found in database: {$role->value}");
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $id, string $passwordHash): bool
    {
        $params = ['id' => $id, 'hash' => $passwordHash];
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function touchLastLogin(int $id, DateTimeImmutable $at): void
    {
        $params = ['id' => $id, 'at' => $at->format('Y-m-d H:i:s')];
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = :at WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    /** @param array<string, mixed> $params */
    private function fetchOne(string $sql, array $params): ?User
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            departmentId: $row['department_id'] !== null ? (int) $row['department_id'] : null,
            role: Role::from((string) $row['role_code']),
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            locale: (string) $row['locale'],
            isActive: (bool) $row['is_active'],
            lastLoginAt: $row['last_login_at'] !== null
                ? new DateTimeImmutable((string) $row['last_login_at'], new DateTimeZone('UTC'))
                : null,
        );
    }
}
