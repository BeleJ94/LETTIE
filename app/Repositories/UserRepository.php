<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Page;
use App\Core\TableRequest;
use App\Domain\Auth\Role;
use App\Domain\Auth\User;
use App\Domain\Auth\UserInput;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class UserRepository extends Repository
{
    public const SORTABLE = [
        'name' => ['u.last_name', 'u.first_name'],
        'email' => ['u.email'],
        'role' => ['r.code'],
        'site_name' => ['s.name'],
        'department_name' => ['d.name'],
        'status' => ['u.is_active'],
        'last_login_at' => ['u.last_login_at'],
    ];

    private const SELECT = 'SELECT u.id, u.site_id, u.department_id, r.code AS role_code, u.email, u.password_hash,
                                   u.first_name, u.last_name, u.locale, u.is_active, u.last_login_at,
                                   u.must_change_password, u.session_version, u.totp_enabled_at
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
        bool $mustChangePassword = false,
    ): int {
        $this->assertInScope($siteId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (site_id, department_id, role_id, email, password_hash, first_name, last_name, locale, must_change_password)
             SELECT :site_id, :department_id, r.id, :email, :password_hash, :first_name, :last_name, :locale, :must_change
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
            'must_change' => $mustChangePassword ? 1 : 0,
            'role' => $role->value,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Role not found in database: {$role->value}");
        }
        return (int) $this->pdo->lastInsertId();
    }

    /** True when another account (any site: the e-mail is the login) already uses this address. */
    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        // Deliberately not scoped: e-mails are unique across the whole application.
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute(['email' => $email, 'id' => $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    /** Fields of the administration form; the password has its own method. */
    public function update(int $id, UserInput $input): void
    {
        $this->assertInScope($input->siteId);
        $params = [
            'id' => $id,
            'site_id' => $input->siteId,
            'department_id' => $input->departmentId,
            'role' => $input->role->value,
            'email' => $input->email,
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
            'locale' => $input->locale,
            'is_active' => $input->isActive ? 1 : 0,
        ];
        $stmt = $this->pdo->prepare(
            'UPDATE users u JOIN roles r ON r.code = :role
             SET u.site_id = :site_id, u.department_id = :department_id, u.role_id = r.id, u.email = :email,
                 u.first_name = :first_name, u.last_name = :last_name, u.locale = :locale, u.is_active = :is_active
             WHERE u.id = :id AND ' . $this->scopeSql('u.site_id', $params)
        );
        $stmt->execute($params);
    }

    public function countActive(): int
    {
        $params = [];
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users u WHERE u.is_active = 1 AND ' . $this->scopeSql('u.site_id', $params));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Server-side table of the administration screen (rows are plain arrays for JSON). */
    public function page(TableRequest $table, ?Role $role, ?int $siteId, bool $includeInactive, ?int $exportLimit = null): Page
    {
        $totalParams = [];
        $total = $this->pdo->prepare('SELECT COUNT(*) FROM users u WHERE ' . $this->scopeSql('u.site_id', $totalParams));
        $total->execute($totalParams);

        $params = [];
        $conditions = [$this->scopeSql('u.site_id', $params)];
        if (!$includeInactive) {
            $conditions[] = 'u.is_active = 1';
        }
        if ($role !== null) {
            $conditions[] = 'r.code = :role';
            $params['role'] = $role->value;
        }
        if ($siteId !== null) {
            $conditions[] = 'u.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        $like = $table->likePattern();
        if ($like !== null) {
            $parts = [];
            foreach (['u.first_name', 'u.last_name', 'u.email', "CONCAT(u.first_name, ' ', u.last_name)"] as $i => $field) {
                $parts[] = "{$field} LIKE :q{$i} ESCAPE '\\\\'";
                $params['q' . $i] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }
        $from = ' FROM users u
                  JOIN roles r ON r.id = u.role_id
                  JOIN sites s ON s.id = u.site_id
                  LEFT JOIN departments d ON d.id = u.department_id
                  WHERE ' . implode(' AND ', $conditions);

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);

        $stmt = $this->pdo->prepare(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.email, r.code AS role, s.name AS site_name,
                    d.name AS department_name, u.is_active, u.last_login_at" . $from
            . self::orderBy(self::SORTABLE, $table->sort, $table->dir, 'u.id')
            // An export takes the whole filtered list, up to its own limit.
            . ($exportLimit !== null ? ' LIMIT ' . max(1, $exportLimit) : ' LIMIT ' . $table->perPage . ' OFFSET ' . $table->offset())
        );
        $stmt->execute($params);
        $rows = array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['status'] = (bool) $r['is_active'] ? 'active' : 'inactive';
            unset($r['is_active']);
            return $r;
        }, $stmt->fetchAll());

        return new Page($rows, (int) $total->fetchColumn(), (int) $count->fetchColumn());
    }

    /**
     * New password: the sessions opened with the former one stop being valid (session_version).
     * updatePasswordHash() below only re-hashes the same password and keeps the sessions.
     */
    public function changePassword(int $id, string $passwordHash, bool $mustChange, DateTimeImmutable $at): bool
    {
        $params = ['id' => $id, 'hash' => $passwordHash, 'must_change' => $mustChange ? 1 : 0, 'at' => $at->format('Y-m-d H:i:s')];
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = :must_change, password_changed_at = :at,
                    session_version = session_version + 1
             WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /**
     * Secret of the authenticator application and period of the last accepted code; null when two-factor is off.
     *
     * @return ?array{secret: string, last_counter: ?int}
     */
    public function totpState(int $id): ?array
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(
            'SELECT totp_secret, totp_last_counter FROM users WHERE id = :id AND totp_enabled_at IS NOT NULL AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) && $row['totp_secret'] !== null
            ? ['secret' => (string) $row['totp_secret'], 'last_counter' => $row['totp_last_counter'] !== null ? (int) $row['totp_last_counter'] : null]
            : null;
    }

    public function enableTotp(int $id, string $secret, int $counter, DateTimeImmutable $at): void
    {
        $params = ['id' => $id, 'secret' => $secret, 'counter' => $counter, 'at' => $at->format('Y-m-d H:i:s')];
        $stmt = $this->pdo->prepare(
            'UPDATE users SET totp_secret = :secret, totp_enabled_at = :at, totp_last_counter = :counter WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    public function disableTotp(int $id): void
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(
            'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_counter = NULL WHERE id = :id AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    /** A code is accepted once: remembers the period of the last one. */
    public function touchTotpCounter(int $id, int $counter): void
    {
        $params = ['id' => $id, 'counter' => $counter];
        $stmt = $this->pdo->prepare('UPDATE users SET totp_last_counter = :counter WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
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
            mustChangePassword: (bool) $row['must_change_password'],
            sessionVersion: (int) $row['session_version'],
            totpEnabled: $row['totp_enabled_at'] !== null,
        );
    }
}
