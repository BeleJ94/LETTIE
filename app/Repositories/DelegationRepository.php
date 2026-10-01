<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Delegation\Delegation;
use DateTimeImmutable;

final class DelegationRepository extends Repository
{
    private const SELECT = "SELECT dl.*,
            CONCAT(u1.first_name, ' ', u1.last_name) AS delegator_name,
            CONCAT(u2.first_name, ' ', u2.last_name) AS delegate_name
        FROM delegations dl
        JOIN users u1 ON u1.id = dl.delegator_id
        JOIN users u2 ON u2.id = dl.delegate_id";

    public function create(
        int $siteId,
        int $delegatorId,
        int $delegateId,
        string $startsOn,
        string $endsOn,
        ?string $reason,
        int $createdBy,
        DateTimeImmutable $at,
    ): int {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO delegations (site_id, delegator_id, delegate_id, starts_on, ends_on, reason, created_by, created_at)
             VALUES (:site_id, :delegator, :delegate, :starts, :ends, :reason, :created_by, :at)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'delegator' => $delegatorId,
            'delegate' => $delegateId,
            'starts' => $startsOn,
            'ends' => $endsOn,
            'reason' => $reason,
            'created_by' => $createdBy,
            'at' => self::sqlDateTime($at),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Delegation
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE dl.id = :id AND ' . $this->scopeSql('dl.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    public function cancel(int $id, DateTimeImmutable $at, int $by): void
    {
        $params = ['id' => $id, 'at' => self::sqlDateTime($at), 'by' => $by];
        $stmt = $this->pdo->prepare(
            'UPDATE delegations SET cancelled_at = :at, cancelled_by = :by
             WHERE id = :id AND cancelled_at IS NULL AND ' . $this->scopeSql('site_id', $params)
        );
        $stmt->execute($params);
    }

    /** @return list<Delegation> non-cancelled delegations of one user */
    public function forDelegator(int $delegatorId): array
    {
        $params = ['delegator' => $delegatorId];
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE dl.delegator_id = :delegator AND dl.cancelled_at IS NULL AND '
            . $this->scopeSql('dl.site_id', $params) . ' ORDER BY dl.starts_on'
        );
        $stmt->execute($params);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /** @return list<Delegation> current and upcoming (not cancelled) */
    public function listFrom(string $today): array
    {
        $params = ['today' => $today];
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE dl.ends_on >= :today AND dl.cancelled_at IS NULL AND '
            . $this->scopeSql('dl.site_id', $params) . ' ORDER BY dl.starts_on, dl.id'
        );
        $stmt->execute($params);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Delegations in force on $date for one site.
     *
     * @return array<int, int> delegator id => delegate id
     */
    public function activeMap(int $siteId, string $date): array
    {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'SELECT delegator_id, delegate_id FROM delegations
             WHERE site_id = :site_id AND cancelled_at IS NULL AND starts_on <= :d1 AND ends_on >= :d2
             ORDER BY id'
        );
        $stmt->execute(['site_id' => $siteId, 'd1' => $date, 'd2' => $date]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['delegator_id']] = (int) $row['delegate_id'];
        }
        return $map;
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Delegation
    {
        return new Delegation(
            id: (int) $r['id'],
            siteId: (int) $r['site_id'],
            delegatorId: (int) $r['delegator_id'],
            delegatorName: (string) $r['delegator_name'],
            delegateId: (int) $r['delegate_id'],
            delegateName: (string) $r['delegate_name'],
            startsOn: (string) $r['starts_on'],
            endsOn: (string) $r['ends_on'],
            reason: $r['reason'] !== null ? (string) $r['reason'] : null,
            createdBy: (int) $r['created_by'],
            cancelledAt: self::utc($r['cancelled_at']),
        );
    }
}
