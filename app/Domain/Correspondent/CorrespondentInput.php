<?php

declare(strict_types=1);

namespace App\Domain\Correspondent;

final class CorrespondentInput
{
    public function __construct(
        public readonly CorrespondentType $type,
        public readonly string $name,
        public readonly ?string $organization,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly string $country,
        public readonly ?string $notes,
        public readonly bool $isActive = true,
    ) {
    }

    /** @return array<string, string|int|bool|null> same keys as Correspondent::auditValues() */
    public function auditValues(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'organization' => $this->organization,
            'email' => $this->email,
            'phone' => $this->phone,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];
    }
}
