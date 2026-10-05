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
        /** The password was chosen by an administrator: it must be replaced before anything else. */
        public readonly bool $mustChangePassword = false,
        /** Incremented at every password change: sessions opened before it are closed. */
        public readonly int $sessionVersion = 1,
        /** Sign-in asks for the code of an authenticator application. */
        public readonly bool $totpEnabled = false,
    ) {
    }

    public function can(Permission $permission): bool
    {
        return $this->isActive && $this->role->can($permission);
    }

    /** @return array<string, string|int|bool|null> fields traced in the activity log (never the password) */
    public function auditValues(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'role' => $this->role->value,
            'site_id' => $this->siteId,
            'department_id' => $this->departmentId,
            'locale' => $this->locale,
            'is_active' => $this->isActive,
        ];
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }
}
