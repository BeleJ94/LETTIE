<?php

declare(strict_types=1);

namespace App\Domain\Correspondent;

final class Correspondent
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
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
        public readonly bool $isActive,
    ) {
    }

    public function displayName(): string
    {
        return $this->organization !== null && $this->organization !== '' && $this->type === CorrespondentType::Person
            ? $this->name . ' (' . $this->organization . ')'
            : $this->name;
    }

    /** @return array<string, string|int|bool|null> */
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
