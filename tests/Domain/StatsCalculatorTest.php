<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Core\Code39;
use App\Domain\RuleViolation;
use App\Domain\Stats\Granularity;
use App\Domain\Stats\StatsCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StatsCalculatorTest extends TestCase
{
    public function testGranularityAutoAndKeys(): void
    {
        self::assertSame(Granularity::Day, Granularity::auto('2026-09-01', '2026-09-30'));
        self::assertSame(Granularity::Week, Granularity::auto('2026-06-01', '2026-09-30'));
        self::assertSame(Granularity::Month, Granularity::auto('2025-10-01', '2026-09-30'));

        self::assertSame('2026-09-30', Granularity::Day->keyOf('2026-09-30'));
        self::assertSame('2026-09', Granularity::Month->keyOf('2026-09-30'));
        // ISO weeks: the last days of December can belong to week 53 or week 1 of the next year.
        self::assertSame('2026-W53', Granularity::Week->keyOf('2026-12-31'));
        self::assertSame('2026-W53', Granularity::Week->keyOf('2027-01-01'));
        self::assertSame('2025-W01', Granularity::Week->keyOf('2024-12-30'));
    }

    public function testBucketKeysHaveNoGaps(): void
    {
        self::assertSame(['2026-07', '2026-08', '2026-09'], StatsCalculator::bucketKeys('2026-07-15', '2026-09-02', Granularity::Month));
        self::assertCount(3, StatsCalculator::bucketKeys('2026-09-28', '2026-09-30', Granularity::Day));
        self::assertSame(['2026-W40', '2026-W41'], StatsCalculator::bucketKeys('2026-09-28', '2026-10-11', Granularity::Week));
    }

    public function testVolumesUseLocalDates(): void
    {
        $mails = [
            ['direction' => 'incoming', 'mail_date' => '2026-09-29 21:30:00'], // 23:30 in Paris → 29/09
            ['direction' => 'incoming', 'mail_date' => '2026-09-29 22:30:00'], // 00:30 in Paris → 30/09
            ['direction' => 'outgoing', 'mail_date' => '2026-09-30 10:00:00'],
            ['direction' => 'incoming', 'mail_date' => '2026-10-05 10:00:00'], // outside the range
        ];
        self::assertSame(
            ['labels' => ['2026-09-29', '2026-09-30'], 'incoming' => [1, 1], 'outgoing' => [0, 1]],
            StatsCalculator::volumes($mails, '2026-09-29', '2026-09-30', Granularity::Day, 'Europe/Paris'),
        );
    }

    public function testProcessingTimes(): void
    {
        $closed = [
            ['department' => 'RH', 'started_at' => '2026-09-01 08:00:00', 'closed_at' => '2026-09-03 08:00:00', 'due_date' => '2026-09-10'],
            ['department' => 'RH', 'started_at' => '2026-09-01 08:00:00', 'closed_at' => '2026-09-05 08:00:00', 'due_date' => '2026-09-04'],
            ['department' => null, 'started_at' => '2026-09-01 08:00:00', 'closed_at' => '2026-09-11 08:00:00', 'due_date' => null],
        ];
        $result = StatsCalculator::processing($closed, 'Europe/Paris');

        self::assertSame(3, $result['count']);
        self::assertSame(5.3, $result['average_days']);
        self::assertSame(4.0, $result['median_days']);
        self::assertSame(1, $result['closed_late']);
        self::assertSame(33.3, $result['late_rate']);
        self::assertSame([
            ['name' => null, 'count' => 1, 'average_days' => 10.0],
            ['name' => 'RH', 'count' => 2, 'average_days' => 3.0],
        ], $result['by_department'], 'slowest first');

        self::assertSame(['count' => 0, 'average_days' => null, 'median_days' => null, 'closed_late' => 0, 'late_rate' => null, 'by_department' => []],
            StatsCalculator::processing([], 'UTC'));
        self::assertSame(2.5, StatsCalculator::median([1.0, 4.0, 2.0, 3.0]));
    }

    public function testOverdueBuckets(): void
    {
        $result = StatsCalculator::overdue([
            ['due_date' => '2026-09-29', 'department' => 'RH'],
            ['due_date' => '2026-09-23', 'department' => 'RH'],
            ['due_date' => '2026-09-22', 'department' => null],
            ['due_date' => '2026-08-29', 'department' => 'IT'],
        ], '2026-09-30');

        self::assertSame(4, $result['total']);
        self::assertSame(['1-7' => 2, '8-30' => 1, '31+' => 1], $result['buckets']);
        self::assertSame(['name' => 'RH', 'count' => 2], $result['by_department'][0]);
    }

    public function testRangeValidation(): void
    {
        StatsCalculator::checkRange('2025-01-01', '2026-12-31');
        foreach ([['2026-09-30', '2026-09-01', 'rules.stats.range_order'], ['2020-01-01', '2026-01-01', 'rules.stats.range_too_long']] as [$from, $to, $key]) {
            try {
                StatsCalculator::checkRange($from, $to);
                self::fail('Expected RuleViolation');
            } catch (RuleViolation $e) {
                self::assertSame($key, $e->violations()['to'][0][0]);
            }
        }
    }

    /* ---------------------------------------------------------------- Code 39 */

    public function testCode39Structure(): void
    {
        $modules = Code39::modules('ENT-2026-00001');
        // (14 characters + 2 start/stop) × 9 elements + 15 inter-character gaps.
        self::assertCount(16 * 9 + 15, $modules);
        // Start character "*" = n w n n w n w n n.
        self::assertSame([2, 5, 2, 2, 5, 2, 5, 2, 2], array_slice($modules, 0, 9));
        // Every character has exactly 3 wide elements out of 9.
        self::assertSame(16 * 3, count(array_filter($modules, static fn (int $w): bool => $w === Code39::WIDE)));
        self::assertSame(Code39::modules('ent-1'), Code39::modules('ENT-1'), 'lowercase is encoded as uppercase');
    }

    public function testCode39Svg(): void
    {
        $svg = Code39::svg('SOR-2026-00042', 40);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        self::assertSame(16 * 5, substr_count($svg, '<rect'), '5 bars per character');
        self::assertStringContainsString('aria-label="SOR-2026-00042"', $svg);
        self::assertNotFalse(simplexml_load_string($svg));

        $this->expectException(InvalidArgumentException::class);
        Code39::modules('É');
    }
}
