<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-09-30 10:00:00')
    {
        $this->now = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
