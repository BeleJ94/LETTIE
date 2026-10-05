<?php

declare(strict_types=1);

namespace App\Repositories;

final class SiteRepository extends Repository
{
    public function findIdByCode(string $code): ?int
    {
        $params = ['code' => $code];
        $stmt = $this->pdo->prepare(
            'SELECT id FROM sites WHERE code = :code AND ' . $this->scopeSql('id', $params)
        );
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private const ROW = 'SELECT s.id, s.code, s.name, s.is_active,
                               (SELECT COUNT(*) FROM users u WHERE u.site_id = s.id AND u.is_active = 1) AS users_count
                        FROM sites s';

    /** @return list<array{id: int, code: string, name: string, is_active: bool, users_count: int}> */
    public function rows(): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(self::ROW . ' WHERE ' . $this->scopeSql('s.id', $params) . ' ORDER BY s.name');
        $stmt->execute($params);
        return array_map(self::row(...), $stmt->fetchAll());
    }

    /** @return ?array{id: int, code: string, name: string, is_active: bool, users_count: int} */
    public function find(int $id): ?array
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(self::ROW . ' WHERE s.id = :id AND ' . $this->scopeSql('s.id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::row($row) : null;
    }

    /** True when another site (any: codes are unique across the application) already uses the code. */
    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        // Deliberately not scoped: the code is unique for the whole application.
        $stmt = $this->pdo->prepare('SELECT 1 FROM sites WHERE code = :code AND id <> :id LIMIT 1');
        $stmt->execute(['code' => $code, 'id' => $exceptId ?? 0]);
        return $stmt->fetchColumn() !== false;
    }

    public function update(int $id, string $code, string $name): void
    {
        $params = ['id' => $id, 'code' => $code, 'name' => $name];
        $stmt = $this->pdo->prepare('UPDATE sites SET code = :code, name = :name WHERE id = :id AND ' . $this->scopeSql('id', $params));
        $stmt->execute($params);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('UPDATE sites SET is_active = :active WHERE id = :id');
        $stmt->execute(['id' => $id, 'active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, code: string, name: string, is_active: bool, users_count: int}
     */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'is_active' => (bool) $r['is_active'], 'users_count' => (int) $r['users_count']];
    }

    /** @return list<array{id: int, code: string, name: string, is_active: bool}> */
    public function listAll(): array
    {
        $params = [];
        $stmt = $this->pdo->prepare('SELECT id, code, name, is_active FROM sites WHERE ' . $this->scopeSql('id', $params) . ' ORDER BY name');
        $stmt->execute($params);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'is_active' => (bool) $r['is_active']], $stmt->fetchAll());
    }

    public function findName(int $id): ?string
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT name FROM sites WHERE id = :id AND ' . $this->scopeSql('id', $params));
        $stmt->execute($params);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    public function create(string $code, string $name): int
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('INSERT INTO sites (code, name) VALUES (:code, :name)');
        $stmt->execute(['code' => $code, 'name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }
}
