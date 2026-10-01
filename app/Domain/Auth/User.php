<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DateTimeImmutable;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly ?int $departmentId,
        public readonly Role $role,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $locale = 'fr',
        public readonly bool $isActive = true,
        public readonly ?DateTimeImmutable $lastLoginAt = null,
    ) {
    }

    public function can(Permission $permission): bool
    {
        return $this->isActive && $this->role->can($permission);
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
