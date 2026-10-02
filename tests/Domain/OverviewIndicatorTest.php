<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Overview\Indicator;
use PHPUnit\Framework\TestCase;

final class OverviewIndicatorTest extends TestCase
{
    public function testAnyOverdueMailIsNegative(): void
    {
        self::assertSame(Indicator::POSITIVE, Indicator::overdue(0));
        self::assertSame(Indicator::NEGATIVE, Indicator::overdue(1));
        self::assertSame(Indicator::NEGATIVE, Indicator::overdue(250));
    }

    public function testPendingWorkIsCriticalNotNegative(): void
    {
        self::assertSame(Indicator::POSITIVE, Indicator::pending(0));
        self::assertSame(Indicator::CRITICAL, Indicator::pending(3));
    }

    public function testLateRateThresholds(): void
    {
        self::assertSame(Indicator::NEUTRAL, Indicator::lateRate(null), 'nothing closed: no judgement');
        self::assertSame(Indicator::POSITIVE, Indicator::lateRate(0.0));
        self::assertSame(Indicator::POSITIVE, Indicator::lateRate(9.9));
        self::assertSame(Indicator::CRITICAL, Indicator::lateRate(10.0));
        self::assertSame(Indicator::CRITICAL, Indicator::lateRate(24.9));
        self::assertSame(Indicator::NEGATIVE, Indicator::lateRate(25.0));
        self::assertSame(Indicator::NEGATIVE, Indicator::lateRate(100.0));
    }

    public function testProcessingTimeIsJudgedAgainstItsTarget(): void
    {
        self::assertSame(Indicator::NEUTRAL, Indicator::processing(null));
        self::assertSame(Indicator::POSITIVE, Indicator::processing(7.5));
        self::assertSame(Indicator::POSITIVE, Indicator::processing(14.0), 'on target');
        self::assertSame(Indicator::CRITICAL, Indicator::processing(14.1));
        self::assertSame(Indicator::CRITICAL, Indicator::processing(21.0));
        self::assertSame(Indicator::NEGATIVE, Indicator::processing(21.1));
    }

    public function testStatesAreUi5SemanticDesigns(): void
    {
        foreach ([Indicator::overdue(1), Indicator::pending(1), Indicator::lateRate(12.0), Indicator::processing(3.0), Indicator::lateRate(null)] as $state) {
            self::assertContains($state, ['Positive', 'Critical', 'Negative', 'Neutral']);
        }
    }
}
