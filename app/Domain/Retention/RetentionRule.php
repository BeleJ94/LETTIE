<?php

declare(strict_types=1);

namespace App\Domain\Retention;

use App\Domain\Mail\Direction;
use DateTimeImmutable;

final class RetentionRule
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $siteId,
        public readonly string $name,
        public readonly ?Direction $direction,
        public readonly int $retentionMonths,
        public readonly RetentionAction $action,
        public readonly bool $isActive,
    ) {
    }

    public function matches(int $siteId, Direction $direction): bool
    {
        return $this->isActive
            && ($this->siteId === null || $this->siteId === $siteId)
            && ($this->direction === null || $this->direction === $direction);
    }

    /** Site-specific beats global; direction-specific beats both directions. */
    public function specificity(): int
    {
        return ($this->siteId !== null ? 2 : 0) + ($this->direction !== null ? 1 : 0);
    }

    /** Mail closed on or before this instant has reached the end of its retention period. */
    public function cutoff(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("-{$this->retentionMonths} months");
    }

    /** @return array<string, string|int|null> */
    public function auditValues(): array
    {
        return [
            'rule_id' => $this->id,
            'rule' => $this->name,
            'retention_months' => $this->retentionMonths,
            'action' => $this->action->value,
        ];
    }
}
