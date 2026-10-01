<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;

/** Append-only (the table rejects UPDATE/DELETE): there is no update method on purpose. */
final class ActivityLogRepository extends Repository
{
    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        ?int $siteId,
        ?int $userId,
        string $entityType,
        int $entityId,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        ?string $ip,
        ?string $userAgent,
        DateTimeImmutable $at,
    ): void {
        if ($siteId !== null) {
            $this->assertInScope($siteId);
        }
        $packedIp = $ip !== null && $ip !== '' ? @inet_pton($ip) : false;
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_log (site_id, user_id, entity_type, entity_id, action, old_values, new_values,
                                       ip_address, user_agent, created_at)
             VALUES (:site_id, :user_id, :entity_type, :entity_id, :action, :old_values, :new_values,
                     :ip, :user_agent, :created_at)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $packedIp === false ? null : $packedIp,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => $at->format(self::DATETIME_FORMAT),
        ]);
    }

    /**
     * History of one entity, oldest first.
     *
     * @return list<array{created_at: DateTimeImmutable, action: string, user_name: ?string, old_values: array<string, mixed>, new_values: array<string, mixed>}>
     */
    public function forEntity(string $entityType, int $entityId): array
    {
        $params = ['type' => $entityType, 'id' => $entityId];
        $stmt = $this->pdo->prepare(
            'SELECT l.created_at, l.action, l.old_values, l.new_values,
                    CONCAT(u.first_name, \' \', u.last_name) AS user_name
             FROM activity_log l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.entity_type = :type AND l.entity_id = :id AND (l.site_id IS NULL OR ' . $this->scopeSql('l.site_id', $params) . ')
             ORDER BY l.created_at, l.id'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'created_at' => self::utc($r['created_at']) ?? new DateTimeImmutable('@0'),
            'action' => (string) $r['action'],
            'user_name' => $r['user_name'] !== null ? (string) $r['user_name'] : null,
            'old_values' => $r['old_values'] !== null ? (array) json_decode((string) $r['old_values'], true) : [],
            'new_values' => $r['new_values'] !== null ? (array) json_decode((string) $r['new_values'], true) : [],
        ], $stmt->fetchAll());
    }
}
