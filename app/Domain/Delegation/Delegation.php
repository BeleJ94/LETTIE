<?php

declare(strict_types=1);

namespace App\Domain\Delegation;

use DateTimeImmutable;

/** While $delegatorId is absent (startsOn..endsOn inclusive), new assignments go to $delegateId. */
final class Delegation
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly int $delegatorId,
        public readonly string $delegatorName,
        public readonly int $delegateId,
        public readonly string $delegateName,
        public readonly string $startsOn,
        public readonly string $endsOn,
        public readonly ?string $reason,
        public readonly int $createdBy,
        public readonly ?DateTimeImmutable $cancelledAt,
    ) {
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    public function isActiveOn(string $date): bool
    {
        return !$this->isCancelled() && $this->startsOn <= $date && $date <= $this->endsOn;
    }

    public function overlaps(string $startsOn, string $endsOn): bool
    {
        return !$this->isCancelled() && $this->startsOn <= $endsOn && $startsOn <= $this->endsOn;
    }

    /** @return array<string, string|int|null> */
    public function auditValues(): array
    {
        return [
            'delegator_id' => $this->delegatorId,
            'delegate_id' => $this->delegateId,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'reason' => $this->reason,
        ];
    }
}
