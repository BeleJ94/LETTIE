<?php

declare(strict_types=1);

namespace App\Domain\Assignment;

use DateTimeImmutable;

final class Assignment
{
    public function __construct(
        public readonly int $id,
        public readonly int $mailId,
        public readonly ?int $userId,
        public readonly ?string $userName,
        public readonly ?int $departmentId,
        public readonly ?string $departmentName,
        public readonly AssignmentRole $role,
        public readonly AssignmentStatus $status,
        public readonly ?string $instructions,
        public readonly ?string $dueDate,
        public readonly ?int $delegatedFromUserId,
        public readonly ?string $delegatedFromName,
        public readonly int $assignedBy,
        public readonly ?string $assignedByName,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $endedAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === AssignmentStatus::Active;
    }

    /** @return array<string, string|int|null> */
    public function auditValues(): array
    {
        return [
            'assignment_id' => $this->id,
            'user_id' => $this->userId,
            'department_id' => $this->departmentId,
            'role' => $this->role->value,
            'due_date' => $this->dueDate,
            'delegated_from_user_id' => $this->delegatedFromUserId,
        ];
    }
}
