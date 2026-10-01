<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Mail\MailStatus;
use DateTimeImmutable;

/**
 * Raw data for statistics. Bounds are UTC instants [from, toExclusive);
 * bucketing by local date is done in App\Domain\Stats\StatsCalculator.
 */
final class StatsRepository extends Repository
{
    private const MAIL_DATE = 'm.mail_date'; // stored, indexed column (migration 019)

    /** @return list<array{direction: string, mail_date: string}> */
    public function mailDates(DateTimeImmutable $from, DateTimeImmutable $toExclusive, ?int $departmentId): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            'SELECT m.direction, ' . self::MAIL_DATE . ' AS mail_date FROM mails m WHERE '
            . $this->range(self::MAIL_DATE, $from, $toExclusive, $departmentId, $params)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => ['direction' => (string) $r['direction'], 'mail_date' => (string) $r['mail_date']], $stmt->fetchAll());
    }

    /**
     * Per department: mail received/sent in the range, and what is pending / overdue today.
     *
     * @return list<array{name: ?string, incoming: int, outgoing: int, pending: int, overdue: int}>
     */
    public function byDepartment(DateTimeImmutable $from, DateTimeImmutable $toExclusive, string $today, ?int $departmentId): array
    {
        $params = ['today' => $today];
        $finishedA = $this->finished($params);
        $finishedB = $this->finished($params);
        $stmt = $this->pdo->prepare(
            "SELECT d.name,
                    SUM(m.direction = 'incoming') AS incoming,
                    SUM(m.direction = 'outgoing') AS outgoing,
                    SUM(m.status NOT IN ({$finishedA})) AS pending,
                    SUM(m.status NOT IN ({$finishedB}) AND m.due_date < :today) AS overdue
             FROM mails m LEFT JOIN departments d ON d.id = m.department_id
             WHERE " . $this->range(self::MAIL_DATE, $from, $toExclusive, $departmentId, $params) . '
             GROUP BY d.id, d.name ORDER BY (SUM(1)) DESC, d.name'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'name' => $r['name'] !== null ? (string) $r['name'] : null,
            'incoming' => (int) $r['incoming'],
            'outgoing' => (int) $r['outgoing'],
            'pending' => (int) $r['pending'],
            'overdue' => (int) $r['overdue'],
        ], $stmt->fetchAll());
    }

    /**
     * Incoming mail closed in the range (processing time = reception → closure).
     *
     * @return list<array{department: ?string, started_at: string, closed_at: string, due_date: ?string}>
     */
    public function closedIncoming(DateTimeImmutable $from, DateTimeImmutable $toExclusive, ?int $departmentId): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            "SELECT d.name AS department, COALESCE(m.received_at, m.created_at) AS started_at, m.closed_at, m.due_date
             FROM mails m LEFT JOIN departments d ON d.id = m.department_id
             WHERE m.direction = 'incoming' AND m.closed_at IS NOT NULL AND "
            . $this->range('m.closed_at', $from, $toExclusive, $departmentId, $params)
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'department' => $r['department'] !== null ? (string) $r['department'] : null,
            'started_at' => (string) $r['started_at'],
            'closed_at' => (string) $r['closed_at'],
            'due_date' => $r['due_date'] !== null ? (string) $r['due_date'] : null,
        ], $stmt->fetchAll());
    }

    /** @return list<array{due_date: string, department: ?string}> pending mail past due today */
    public function overduePending(string $today, ?int $departmentId): array
    {
        $params = ['today' => $today];
        $finished = $this->finished($params);
        $sql = "SELECT m.due_date, d.name AS department FROM mails m LEFT JOIN departments d ON d.id = m.department_id
                WHERE m.due_date < :today AND m.status NOT IN ({$finished}) AND " . $this->scopeSql('m.site_id', $params);
        if ($departmentId !== null) {
            $sql .= ' AND m.department_id = :dept';
            $params['dept'] = $departmentId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'due_date' => (string) $r['due_date'],
            'department' => $r['department'] !== null ? (string) $r['department'] : null,
        ], $stmt->fetchAll());
    }

    /** @return list<array{name: string, incoming: int, outgoing: int, total: int}> */
    public function topCorrespondents(DateTimeImmutable $from, DateTimeImmutable $toExclusive, ?int $departmentId, int $limit = 10): array
    {
        $params = [];
        $stmt = $this->pdo->prepare(
            "SELECT STRAIGHT_JOIN c.name, SUM(m.direction = 'incoming') AS incoming, SUM(m.direction = 'outgoing') AS outgoing, COUNT(*) AS total
             FROM mails m JOIN correspondents c ON c.id = m.correspondent_id
             WHERE " . $this->range(self::MAIL_DATE, $from, $toExclusive, $departmentId, $params) . '
             GROUP BY c.id, c.name ORDER BY total DESC, c.name LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'name' => (string) $r['name'],
            'incoming' => (int) $r['incoming'],
            'outgoing' => (int) $r['outgoing'],
            'total' => (int) $r['total'],
        ], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $params */
    private function range(string $column, DateTimeImmutable $from, DateTimeImmutable $toExclusive, ?int $departmentId, array &$params): string
    {
        $params['r_from'] = self::sqlDateTime($from);
        $params['r_to'] = self::sqlDateTime($toExclusive);
        $sql = "{$column} >= :r_from AND {$column} < :r_to AND " . $this->scopeSql('m.site_id', $params);
        if ($departmentId !== null) {
            $sql .= ' AND m.department_id = :r_dept';
            $params['r_dept'] = $departmentId;
        }
        return $sql;
    }

    /** @param array<string, mixed> $params */
    private function finished(array &$params): string
    {
        $in = [];
        foreach (MailStatus::finishedValues() as $i => $value) {
            // Each use of the list needs its own placeholders (native prepares).
            $name = 'fin' . count($params) . '_' . $i;
            $in[] = ':' . $name;
            $params[$name] = $value;
        }
        return implode(', ', $in);
    }
}
