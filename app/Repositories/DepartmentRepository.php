<?php

declare(strict_types=1);

namespace App\Repositories;

final class DepartmentRepository extends Repository
{
    public function findSiteId(int $id): ?int
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT site_id FROM departments WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
        $siteId = $stmt->fetchColumn();
        return $siteId === false ? null : (int) $siteId;
    }

    /** @return list<array{id: int, site_id: int, name: string}> */
    public function listActive(): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            'SELECT id, site_id, name FROM departments WHERE is_active = 1 AND ' . $this->scopeSql('site_id', $params) . ' ORDER BY name'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'site_id' => (int) $r['site_id'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll());
    }

    private const ROW = 'SELECT d.id, d.site_id, s.name AS site_name, d.code, d.name, d.is_active,
                               (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) AS users_count
                        FROM departments d JOIN sites s ON s.id = d.site_id';

    /** @return list<array{id: int, site_id: int, site_name: string, code: string, name: string, is_active: bool, users_count: int}> */
    public function rows(): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(self::ROW . ' WHERE ' . $this->scopeSql('d.site_id', $params) . ' ORDER BY s.name, d.name');
        $stmt->execute($params);
        return array_map(self::row(...), $stmt->fetchAll());
    }

    /** @return ?array{id: int, site_id: int, site_name: string, code: string, name: string, is_active: bool, users_count: int} */
    public function find(int $id): ?array
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(self::ROW . ' WHERE d.id = :id AND ' . $this->scopeSql('d.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::row($row) : null;
    }

    public function codeTaken(int $siteId, string $code, ?int $exceptId = null): bool
    {
        $params = ['site_id' => $siteId, 'code' => $code, 'id' => $exceptId ?? 0];
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM departments WHERE site_id = :site_id AND code = :code AND id <> :id AND ' . $this->scopeSql('site_id', $params) . ' LIMIT 1'
        );
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public function create(int $siteId, string $code, string $name): int
    {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare('INSERT INTO departments (site_id, code, name) VALUES (:site_id, :code, :name)');
        $stmt->execute(['site_id' => $siteId, 'code' => $code, 'name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $code, string $name): void
    {
        $params = ['id' => $id, 'code' => $code, 'name' => $name];
        $stmt = $this->pdo->prepare('UPDATE departments SET code = :code, name = :name WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
    }

    public function setActive(int $id, bool $active): void
    {
        $params = ['id' => $id, 'active' => $active ? 1 : 0];
        $stmt = $this->pdo->prepare('UPDATE departments SET is_active = :active WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $r
     * @return array{id: int, site_id: int, site_name: string, code: string, name: string, is_active: bool, users_count: int}
     */
    private static function row(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'site_id' => (int) $r['site_id'], 'site_name' => (string) $r['site_name'], 'code' => (string) $r['code'],
            'name' => (string) $r['name'], 'is_active' => (bool) $r['is_active'], 'users_count' => (int) $r['users_count'],
        ];
    }

    public function findName(int $id): ?string
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT name FROM departments WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }
}
