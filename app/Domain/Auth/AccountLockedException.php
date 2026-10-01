<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DateTimeImmutable;
use RuntimeException;

final class AccountLockedException extends RuntimeException
{
    public function __construct(private readonly DateTimeImmutable $until)
    {
        parent::__construct('Too many failed login attempts.');
    }

    public function until(): DateTimeImmutable
    {
        return $this->until;
    }

    public function minutesRemaining(DateTimeImmutable $now): int
    {
        return max(1, (int) ceil(($this->until->getTimestamp() - $now->getTimestamp()) / 60));
    }
}
