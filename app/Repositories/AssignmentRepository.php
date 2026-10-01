<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Assignment\Assignment;
use App\Domain\Assignment\AssignmentRole;
use App\Domain\Assignment\AssignmentStatus;
use DateTimeImmutable;

/** Assignments inherit the site of their mail (scope applied through the join). */
final class AssignmentRepository extends Repository
{
    private const SELECT = "SELECT a.*,
            CONCAT(u.first_name, ' ', u.last_name) AS user_name,
            d.name AS department_name,
            CONCAT(df.first_name, ' ', df.last_name) AS delegated_from_name,
            CONCAT(ab.first_name, ' ', ab.last_name) AS assigned_by_name
        FROM assignments a
        JOIN mails m ON m.id = a.mail_id
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN departments d ON d.id = a.department_id
        LEFT JOIN users df ON df.id = a.delegated_from_user_id
        LEFT JOIN users ab ON ab.id = a.assigned_by";

    public function create(
        int $mailId,
        int $siteId,
        ?int $userId,
        ?int $departmentId,
        AssignmentRole $role,
        ?string $instructions,
        ?string $dueDate,
        ?int $delegatedFromUserId,
        int $assignedBy,
        DateTimeImmutable $at,
    ): int {
        $this->assertInScope($siteId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO assignments (mail_id, user_id, department_id, role, instructions, due_date,
                                      delegated_from_user_id, assigned_by, created_at)
             SELECT m.id, :user_id, :department_id, :role, :instructions, :due_date, :delegated_from, :assigned_by, :at
             FROM mails m WHERE m.id = :mail_id AND m.site_id = :site_id'
        );
        $stmt->execute([
            'mail_id' => $mailId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'department_id' => $departmentId,
            'role' => $role->value,
            'instructions' => $instructions,
            'due_date' => $dueDate,
            'delegated_from' => $delegatedFromUserId,
            'assigned_by' => $assignedBy,
            'at' => self::sqlDateTime($at),
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Mail {$mailId} not found on site {$siteId}.");
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Assignment
    {
        $params = ['id' => $id];
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE a.id = :id AND ' . $this->scopeSql('m.site_id', $params));
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return list<Assignment> active first, then most recent */
    public function listForMail(int $mailId): array
    {
        $params = ['mail_id' => $mailId];
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE a.mail_id = :mail_id AND ' . $this->scopeSql('m.site_id', $params)
            . " ORDER BY a.status = 'active' DESC, a.created_at DESC, a.id DESC"
        );
        $stmt->execute($params);
        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function activeForAction(int $mailId): ?Assignment
    {
        $params = ['mail_id' => $mailId];
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE a.mail_id = :mail_id AND a.status = 'active' AND a.role = 'for_action' AND "
            . $this->scopeSql('m.site_id', $params) . ' ORDER BY a.id DESC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Is one of $userIds (the user and the absent colleagues they replace), or
     * $departmentId, the target of an active assignment of this mail?
     *
     * @param list<int> $userIds
     */
    public function isAssignee(int $mailId, array $userIds, ?int $departmentId): bool
    {
        $params = ['mail_id' => $mailId];
        $targets = [];
        foreach (array_values($userIds) as $i => $userId) {
            $params['u' . $i] = $userId;
            $targets[] = ':u' . $i;
        }
        $conditions = [];
        if ($targets !== []) {
            $conditions[] = 'a.user_id IN (' . implode(', ', $targets) . ')';
        }
        if ($departmentId !== null) {
            $conditions[] = '(a.user_id IS NULL AND a.department_id = :department_id)';
            $params['department_id'] = $departmentId;
        }
        if ($conditions === []) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM assignments a JOIN mails m ON m.id = a.mail_id
             WHERE a.mail_id = :mail_id AND a.status = 'active' AND (" . implode(' OR ', $conditions) . ')
               AND ' . $this->scopeSql('m.site_id', $params) . ' LIMIT 1'
        );
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    public function end(int $id, AssignmentStatus $status, DateTimeImmutable $at, int $endedBy): void
    {
        $params = ['id' => $id, 'status' => $status->value, 'at' => self::sqlDateTime($at), 'by' => $endedBy];
        $stmt = $this->pdo->prepare(
            "UPDATE assignments a JOIN mails m ON m.id = a.mail_id
             SET a.status = :status, a.ended_at = :at, a.ended_by = :by
             WHERE a.id = :id AND a.status = 'active' AND " . $this->scopeSql('m.site_id', $params)
        );
        $stmt->execute($params);
    }

    /** Ends every active assignment of a mail (answer, close); returns how many. */
    public function endAllActive(int $mailId, AssignmentStatus $status, DateTimeImmutable $at, int $endedBy): int
    {
        $params = ['mail_id' => $mailId, 'status' => $status->value, 'at' => self::sqlDateTime($at), 'by' => $endedBy];
        $stmt = $this->pdo->prepare(
            "UPDATE assignments a JOIN mails m ON m.id = a.mail_id
             SET a.status = :status, a.ended_at = :at, a.ended_by = :by
             WHERE a.mail_id = :mail_id AND a.status = 'active' AND " . $this->scopeSql('m.site_id', $params)
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): Assignment
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return new Assignment(
            id: (int) $r['id'],
            mailId: (int) $r['mail_id'],
            userId: $int($r['user_id']),
            userName: $str($r['user_name']),
            departmentId: $int($r['department_id']),
            departmentName: $str($r['department_name']),
            role: AssignmentRole::from((string) $r['role']),
            status: AssignmentStatus::from((string) $r['status']),
            instructions: $str($r['instructions']),
            dueDate: $str($r['due_date']),
            delegatedFromUserId: $int($r['delegated_from_user_id']),
            delegatedFromName: $str($r['delegated_from_name']),
            assignedBy: (int) $r['assigned_by'],
            assignedByName: $str($r['assigned_by_name']),
            createdAt: self::utc($r['created_at']) ?? new DateTimeImmutable('@0'),
            endedAt: self::utc($r['ended_at']),
        );
    }
}
