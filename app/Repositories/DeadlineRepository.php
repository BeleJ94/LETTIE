<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Mail\MailStatus;
use DateTimeImmutable;

/** Read models for deadlines: reminders, dashboard counters and lists. */
final class DeadlineRepository extends Repository
{
    /**
     * Pending mail with a due date on or before $until.
     *
     * @return list<array{mail_id: int, site_id: int, reference: string, subject: string, due_date: string}>
     */
    public function pendingDueBy(string $until): array
    {
        $params = ['until' => $until];
        $stmt = $this->pdo->prepare(
            'SELECT m.id AS mail_id, m.site_id, m.reference, m.subject, m.due_date FROM mails m
             WHERE m.due_date IS NOT NULL AND m.due_date <= :until AND ' . $this->notFinished($params) . '
               AND ' . $this->scopeSql('m.site_id', $params) . ' ORDER BY m.due_date, m.id'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'mail_id' => (int) $r['mail_id'],
            'site_id' => (int) $r['site_id'],
            'reference' => (string) $r['reference'],
            'subject' => (string) $r['subject'],
            'due_date' => (string) $r['due_date'],
        ], $stmt->fetchAll());
    }

    /**
     * Who is responsible for each mail: active "for action" users; for a
     * department-only assignment, the active users of that department; with
     * no owner at all, the site's dispatchers (secretariat, heads of department).
     *
     * @param list<int> $mailIds
     * @return array<int, list<int>> mail id => user ids
     */
    public function responsibleUsers(array $mailIds): array
    {
        if ($mailIds === []) {
            return [];
        }
        // Native prepared statements cannot reuse a placeholder: one set per half of the UNION.
        $params = [];
        $lists = [];
        foreach (['a', 'b'] as $set) {
            $in = [];
            foreach (array_values($mailIds) as $i => $id) {
                $in[] = ":{$set}{$i}";
                $params[$set . $i] = $id;
            }
            $lists[$set] = implode(', ', $in);
        }

        $stmt = $this->pdo->prepare(
            "SELECT a.mail_id, a.user_id FROM assignments a
              WHERE a.mail_id IN ({$lists['a']}) AND a.status = 'active' AND a.role = 'for_action' AND a.user_id IS NOT NULL
             UNION
             SELECT a.mail_id, u.id FROM assignments a
               JOIN users u ON u.department_id = a.department_id AND u.is_active = 1
              WHERE a.mail_id IN ({$lists['b']}) AND a.status = 'active' AND a.role = 'for_action' AND a.user_id IS NULL"
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['mail_id']][] = (int) $row['user_id'];
        }

        $orphans = array_values(array_diff($mailIds, array_keys($result)));
        if ($orphans !== []) {
            foreach ($this->dispatchersBySite($orphans) as $mailId => $users) {
                $result[$mailId] = $users;
            }
        }
        return $result;
    }

    /**
     * Counters for a set of users (their assignments) or, with null, the whole scope.
     *
     * @param list<int>|null $userIds
     * @return array{overdue: int, today: int, week: int}
     */
    public function counters(?array $userIds, string $today, string $weekEnd): array
    {
        $params = ['t1' => $today, 't2' => $today, 't3' => $today, 'w' => $weekEnd];
        $sql = 'SELECT SUM(m.due_date < :t1) AS overdue, SUM(m.due_date = :t2) AS today,
                       SUM(m.due_date > :t3 AND m.due_date <= :w) AS week
                FROM mails m WHERE m.due_date IS NOT NULL AND ' . $this->notFinished($params) . '
                  AND ' . $this->scopeSql('m.site_id', $params) . $this->assignedTo($userIds, $params);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return ['overdue' => (int) ($row['overdue'] ?? 0), 'today' => (int) ($row['today'] ?? 0), 'week' => (int) ($row['week'] ?? 0)];
    }

    /**
     * Workload counters of the scope for the home page: incoming mail waiting for a first
     * assignment, mail still being processed, and mail registered since $dayStart (UTC).
     *
     * @return array{unassigned: int, pending: int, registered_today: int}
     */
    public function workload(DateTimeImmutable $dayStart): array
    {
        // Two queries so that each one uses an index: pending mail by (site_id, status), then today's registrations.
        $params = ['registered' => MailStatus::Registered->value];
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(m.status = :registered AND m.direction = 'incoming'), 0) AS unassigned, COUNT(*) AS pending
             FROM mails m WHERE " . $this->notFinished($params) . ' AND ' . $this->scopeSql('m.site_id', $params)
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        $params = ['day_start' => $dayStart->format('Y-m-d H:i:s')];
        $today = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mails m WHERE m.created_at >= :day_start AND ' . $this->scopeSql('m.site_id', $params)
        );
        $today->execute($params);

        return [
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'registered_today' => (int) $today->fetchColumn(),
        ];
    }

    /**
     * Next deadlines (overdue first).
     *
     * @param list<int>|null $userIds
     * @return list<array{id: int, reference: string, subject: string, due_date: string, status: string, priority: string}>
     */
    public function upcoming(?array $userIds, string $until, int $limit = 10): array
    {
        $params = ['until' => $until];
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.reference, m.subject, m.due_date, m.status, m.priority FROM mails m
             WHERE m.due_date IS NOT NULL AND m.due_date <= :until AND ' . $this->notFinished($params) . '
               AND ' . $this->scopeSql('m.site_id', $params) . $this->assignedTo($userIds, $params) . '
             ORDER BY m.due_date, m.priority DESC, m.id LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'reference' => (string) $r['reference'],
            'subject' => (string) $r['subject'],
            'due_date' => (string) $r['due_date'],
            'status' => (string) $r['status'],
            'priority' => (string) $r['priority'],
        ], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $params */
    private function notFinished(array &$params): string
    {
        $in = [];
        foreach (MailStatus::finishedValues() as $i => $value) {
            $in[] = ':fin' . $i;
            $params['fin' . $i] = $value;
        }
        return 'm.status NOT IN (' . implode(', ', $in) . ')';
    }

    /**
     * @param list<int>|null $userIds
     * @param array<string, mixed> $params
     */
    private function assignedTo(?array $userIds, array &$params): string
    {
        if ($userIds === null) {
            return '';
        }
        if ($userIds === []) {
            return ' AND 1 = 0';
        }
        $in = [];
        foreach (array_values($userIds) as $i => $id) {
            $in[] = ':u' . $i;
            $params['u' . $i] = $id;
        }
        return " AND EXISTS (SELECT 1 FROM assignments a WHERE a.mail_id = m.id AND a.status = 'active' AND a.user_id IN (" . implode(', ', $in) . '))';
    }

    /**
     * @param list<int> $mailIds
     * @return array<int, list<int>>
     */
    private function dispatchersBySite(array $mailIds): array
    {
        $params = [];
        $in = [];
        foreach ($mailIds as $i => $id) {
            $in[] = ':m' . $i;
            $params['m' . $i] = $id;
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.id AS mail_id, u.id AS user_id FROM mails m
               JOIN users u ON u.site_id = m.site_id AND u.is_active = 1
               JOIN roles r ON r.id = u.role_id AND r.code IN ('secretariat', 'head_of_department')
              WHERE m.id IN (" . implode(', ', $in) . ')'
        );
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['mail_id']][] = (int) $row['user_id'];
        }
        return $result;
    }
}
