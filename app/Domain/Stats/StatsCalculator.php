<?php

declare(strict_types=1);

namespace App\Domain\Stats;

use App\Domain\RuleViolation;
use DateTimeImmutable;
use DateTimeZone;

/** Pure statistics computations (inputs come from StatsRepository). */
final class StatsCalculator
{
    public const MAX_RANGE_DAYS = 731;

    /** Overdue age buckets, in days late. */
    public const OVERDUE_BUCKETS = ['1-7' => [1, 7], '8-30' => [8, 30], '31+' => [31, PHP_INT_MAX]];

    /** @throws RuleViolation */
    public static function checkRange(string $from, string $to): void
    {
        if ($to < $from) {
            throw RuleViolation::single('to', 'rules.stats.range_order');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_RANGE_DAYS) {
            throw RuleViolation::single('to', 'rules.stats.range_too_long', ['max' => self::MAX_RANGE_DAYS]);
        }
    }

    /**
     * Every bucket of the range, in order (empty periods included, so charts have no gaps).
     *
     * @return list<string>
     */
    public static function bucketKeys(string $from, string $to, Granularity $granularity): array
    {
        $keys = [];
        $day = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($day <= $end) {
            $keys[$granularity->keyOf($day->format('Y-m-d'))] = true;
            $day = $day->modify('+1 day');
        }
        return array_keys($keys);
    }

    /**
     * Mail counts per bucket and direction. $mails hold UTC instants; they are
     * bucketed by their calendar date in $timeZone.
     *
     * @param list<array{direction: string, mail_date: string}> $mails
     * @return array{labels: list<string>, incoming: list<int>, outgoing: list<int>}
     */
    public static function volumes(array $mails, string $from, string $to, Granularity $granularity, string $timeZone): array
    {
        $keys = self::bucketKeys($from, $to, $granularity);
        $counts = ['incoming' => array_fill_keys($keys, 0), 'outgoing' => array_fill_keys($keys, 0)];
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone($timeZone);
        // Time zones shift whole hours: convert each UTC hour once (≤ 8,760 a year instead of one per mail).
        $bucketOfHour = [];
        foreach ($mails as $mail) {
            $hour = substr($mail['mail_date'], 0, 13);
            $key = $bucketOfHour[$hour] ??= $granularity->keyOf(
                (new DateTimeImmutable($hour . ':00:00', $utc))->setTimezone($local)->format('Y-m-d')
            );
            if (isset($counts[$mail['direction']][$key])) {
                $counts[$mail['direction']][$key]++;
            }
        }
        return ['labels' => $keys, 'incoming' => array_values($counts['incoming']), 'outgoing' => array_values($counts['outgoing'])];
    }

    /**
     * Processing time in calendar days (start → closure), overall and per department.
     *
     * @param list<array{department: ?string, started_at: string, closed_at: string, due_date: ?string}> $closed
     * @return array{count: int, average_days: ?float, median_days: ?float, closed_late: int, late_rate: ?float,
     *               by_department: list<array{name: ?string, count: int, average_days: float}>}
     */
    public static function processing(array $closed, string $timeZone): array
    {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone($timeZone);
        $all = [];
        $byDept = [];
        $late = 0;
        $localDayOfHour = [];
        foreach ($closed as $row) {
            // Plain UTC timestamps: no DateTime object per row (tens of thousands of rows on large sites).
            $start = strtotime($row['started_at'] . ' UTC');
            $end = strtotime($row['closed_at'] . ' UTC');
            $days = max(0.0, ($end - $start) / 86400);
            $all[] = $days;
            $byDept[$row['department'] ?? ''][] = $days;
            if ($row['due_date'] !== null) {
                $hour = substr($row['closed_at'], 0, 13);
                $closedDay = $localDayOfHour[$hour] ??= (new DateTimeImmutable($hour . ':00:00', $utc))->setTimezone($local)->format('Y-m-d');
                if ($closedDay > $row['due_date']) {
                    $late++;
                }
            }
        }
        $departments = [];
        foreach ($byDept as $name => $values) {
            $departments[] = ['name' => $name === '' ? null : $name, 'count' => count($values), 'average_days' => round(array_sum($values) / count($values), 1)];
        }
        usort($departments, static fn (array $a, array $b): int => $b['average_days'] <=> $a['average_days']);

        return [
            'count' => count($all),
            'average_days' => $all === [] ? null : round(array_sum($all) / count($all), 1),
            'median_days' => self::median($all),
            'closed_late' => $late,
            'late_rate' => $all === [] ? null : round($late * 100 / count($all), 1),
            'by_department' => $departments,
        ];
    }

    /**
     * @param list<array{due_date: string, department: ?string}> $overdue pending mail past their due date
     * @return array{total: int, buckets: array<string, int>, by_department: list<array{name: ?string, count: int}>}
     */
    public static function overdue(array $overdue, string $today): array
    {
        $buckets = array_fill_keys(array_keys(self::OVERDUE_BUCKETS), 0);
        $byDept = [];
        $todayTs = strtotime($today);
        foreach ($overdue as $row) {
            $late = (int) round(($todayTs - strtotime($row['due_date'])) / 86400);
            foreach (self::OVERDUE_BUCKETS as $key => [$min, $max]) {
                if ($late >= $min && $late <= $max) {
                    $buckets[$key]++;
                    break;
                }
            }
            $name = $row['department'] ?? '';
            $byDept[$name] = ($byDept[$name] ?? 0) + 1;
        }
        arsort($byDept);
        $departments = [];
        foreach ($byDept as $name => $count) {
            $departments[] = ['name' => $name === '' ? null : (string) $name, 'count' => $count];
        }
        return ['total' => count($overdue), 'buckets' => $buckets, 'by_department' => $departments];
    }

    /** @param list<float> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
