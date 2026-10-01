<?php

declare(strict_types=1);

namespace Tools\Demo;

use App\Core\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** Clock moved forward by the simulation so every action happens at its own date. */
final class DemoClock extends Clock
{
    private DateTimeImmutable $at;

    public function __construct(?DateTimeImmutable $at = null)
    {
        $this->at = ($at ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }

    public function set(DateTimeImmutable $at): void
    {
        $this->at = $at->setTimezone(new DateTimeZone('UTC'));
    }

    public function reset(): void
    {
        $this->at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
