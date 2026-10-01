<?php

declare(strict_types=1);

namespace App\Domain\Stats;

enum Granularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Readable default for a range: days up to a month, weeks up to ~6 months, then months. */
    public static function auto(string $from, string $to): self
    {
        $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        return match (true) {
            $days <= 31 => self::Day,
            $days <= 186 => self::Week,
            default => self::Month,
        };
    }

    /** Bucket key of a local calendar date "Y-m-d": 2026-09-30 / 2026-W40 (ISO) / 2026-09. */
    public function keyOf(string $date): string
    {
        $d = new \DateTimeImmutable($date);
        return match ($this) {
            self::Day => $d->format('Y-m-d'),
            self::Week => $d->format('o-\WW'),
            self::Month => $d->format('Y-m'),
        };
    }
}
