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
