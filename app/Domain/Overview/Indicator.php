<?php

declare(strict_types=1);

namespace App\Domain\Overview;

/**
 * Semantic state of the indicators of the Overview Page (docs/FIORI_DESIGN.md §15).
 * The state decides the Fiori colour (positive, critical, negative): a view never picks one.
 */
final class Indicator
{
    public const POSITIVE = 'Positive';
    public const CRITICAL = 'Critical';
    public const NEGATIVE = 'Negative';
    public const NEUTRAL = 'Neutral';

    /** Share of mail closed after its due date (%): watch from 10, act from 25. */
    public const LATE_RATE_CRITICAL = 10.0;
    public const LATE_RATE_NEGATIVE = 25.0;

    /**
     * Average processing time (calendar days). The target is the default deadline of a
     * normal-priority mail (10 working days, i.e. two weeks); beyond one and a half times it, act.
     */
    public const PROCESSING_TARGET_DAYS = 14.0;
    public const PROCESSING_NEGATIVE_FACTOR = 1.5;

    /** Overdue mail: any overdue mail is a failure to meet a deadline. */
    public static function overdue(int $count): string
    {
        return $count > 0 ? self::NEGATIVE : self::POSITIVE;
    }

    /** Mail waiting for an action that is not late yet (to assign, due this week). */
    public static function pending(int $count): string
    {
        return $count > 0 ? self::CRITICAL : self::POSITIVE;
    }

    /** @param ?float $rate percentage; null when nothing was closed in the period */
    public static function lateRate(?float $rate): string
    {
        return match (true) {
            $rate === null => self::NEUTRAL,
            $rate >= self::LATE_RATE_NEGATIVE => self::NEGATIVE,
            $rate >= self::LATE_RATE_CRITICAL => self::CRITICAL,
            default => self::POSITIVE,
        };
    }

    /** @param ?float $days average; null when nothing was closed in the period */
    public static function processing(?float $days): string
    {
        return match (true) {
            $days === null => self::NEUTRAL,
            $days > self::PROCESSING_TARGET_DAYS * self::PROCESSING_NEGATIVE_FACTOR => self::NEGATIVE,
            $days > self::PROCESSING_TARGET_DAYS => self::CRITICAL,
            default => self::POSITIVE,
        };
    }
}
