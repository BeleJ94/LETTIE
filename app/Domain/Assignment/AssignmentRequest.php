<?php

declare(strict_types=1);

namespace App\Domain\Assignment;

final class AssignmentRequest
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?int $departmentId,
        public readonly AssignmentRole $role,
        public readonly ?string $instructions = null,
        public readonly ?string $dueDate = null,
    ) {
    }
}
