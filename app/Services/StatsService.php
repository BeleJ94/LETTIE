<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Domain\Stats\Granularity;
use App\Domain\Stats\StatsCalculator;
use App\Repositories\StatsRepository;
use DateTimeImmutable;
use DateTimeZone;

/** Statistics dashboard (JSON consumed by dashboard.js). */
final class StatsService
{
    public function __construct(
        private readonly StatsRepository $stats,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param string $from local date "Y-m-d" (inclusive)
     * @param string $to local date "Y-m-d" (inclusive)
     * @return array<string, mixed>
     * @throws \App\Domain\RuleViolation
     */
    public function dashboard(string $from, string $to, ?Granularity $granularity = null, ?int $departmentId = null): array
    {
        StatsCalculator::checkRange($from, $to);
        $granularity ??= Granularity::auto($from, $to);
        $zone = date_default_timezone_get();
        [$fromUtc, $toUtc] = self::bounds($from, $to, $zone);
        $today = $this->today();

        $volumes = StatsCalculator::volumes($this->stats->mailDates($fromUtc, $toUtc, $departmentId), $from, $to, $granularity, $zone);

        return [
            'period' => ['from' => $from, 'to' => $to, 'granularity' => $granularity->value, 'today' => $today],
            'totals' => ['incoming' => array_sum($volumes['incoming']), 'outgoing' => array_sum($volumes['outgoing'])],
            'volumes' => $volumes,
            'departments' => $this->stats->byDepartment($fromUtc, $toUtc, $today, $departmentId),
            'processing' => StatsCalculator::processing($this->stats->closedIncoming($fromUtc, $toUtc, $departmentId), $zone),
            'overdue' => StatsCalculator::overdue($this->stats->overduePending($today, $departmentId), $today),
            'correspondents' => $this->stats->topCorrespondents($fromUtc, $toUtc, $departmentId, 10),
        ];
    }

    /** Default range: the last 12 months up to today. */
    public function defaultRange(): array
    {
        $today = $this->today();
        return [(new DateTimeImmutable($today))->modify('-1 year +1 day')->format('Y-m-d'), $today];
    }

    public function today(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /**
     * Local calendar days [from, to] → UTC instants [from 00:00, day after to 00:00).
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function bounds(string $from, string $to, string $zone): array
    {
        $tz = new DateTimeZone($zone);
        $utc = new DateTimeZone('UTC');
        return [
            (new DateTimeImmutable($from, $tz))->setTimezone($utc),
            (new DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc),
        ];
    }
}
