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

    public function findName(int $id): ?string
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare('SELECT name FROM departments WHERE id = :id AND ' . $this->scopeSql('site_id', $params));
        $stmt->execute($params);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }
}
