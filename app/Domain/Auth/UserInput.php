<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** Fields of a user account as entered by an administrator (the password is handled apart). */
final class UserInput
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly Role $role,
        public readonly int $siteId,
        public readonly ?int $departmentId,
        public readonly string $locale = 'fr',
        public readonly bool $isActive = true,
    ) {
    }

    /** @return array<string, string|int|bool|null> same keys as User::auditValues() */
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
}
