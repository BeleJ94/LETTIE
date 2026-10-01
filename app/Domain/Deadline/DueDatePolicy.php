<?php

declare(strict_types=1);

namespace App\Domain\Deadline;

use App\Domain\Mail\Priority;
use DateTimeImmutable;

/**
 * Deadlines. Default processing time of incoming mail, in working days
 * (Monday–Friday, French public holidays excluded), by priority.
 */
final class DueDatePolicy
{
    /** @var array<string, int> priority => working days */
    public const DEFAULT_WORKING_DAYS = [
        'urgent' => 2,
        'high' => 5,
        'normal' => 10,
        'low' => 20,
    ];

    /** A due date within this many calendar days is "soon". */
    public const SOON_DAYS = 3;

    /** Default due date for mail registered on $registrationDate ("Y-m-d"). */
    public static function defaultDueDate(Priority $priority, string $registrationDate): string
    {
        return self::addWorkingDays($registrationDate, self::DEFAULT_WORKING_DAYS[$priority->value]);
    }

    public static function addWorkingDays(string $date, int $days): string
    {
        $current = new DateTimeImmutable($date);
        $added = 0;
        while ($added < $days) {
            $current = $current->modify('+1 day');
            if (self::isWorkingDay($current->format('Y-m-d'))) {
                $added++;
            }
        }
        return $current->format('Y-m-d');
    }

    public static function isWorkingDay(string $date): bool
    {
        $day = new DateTimeImmutable($date);
        return (int) $day->format('N') < 6 && !in_array($date, self::publicHolidays((int) $day->format('Y')), true);
    }

    /**
     * French public holidays (metropolitan France).
     *
     * @return list<string>
     */
    public static function publicHolidays(int $year): array
    {
        $easter = self::easterSunday($year);
        return [
            "{$year}-01-01",
            $easter->modify('+1 day')->format('Y-m-d'),   // Easter Monday
            "{$year}-05-01",
            "{$year}-05-08",
            $easter->modify('+39 days')->format('Y-m-d'), // Ascension
            $easter->modify('+50 days')->format('Y-m-d'), // Whit Monday
            "{$year}-07-14",
            "{$year}-08-15",
            "{$year}-11-01",
            "{$year}-11-11",
            "{$year}-12-25",
        ];
    }

    /** Gregorian Easter Sunday (Meeus/Jones/Butcher), without the calendar extension. */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Deadline state of a mail on $today (all dates "Y-m-d", calendar days). */
    public static function status(?string $dueDate, string $today, bool $finished, int $soonDays = self::SOON_DAYS): DueStatus
    {
        if ($dueDate === null || $finished) {
            return DueStatus::None;
        }
        if ($dueDate < $today) {
            return DueStatus::Overdue;
        }
        if ($dueDate === $today) {
            return DueStatus::Today;
        }
        $soonLimit = (new DateTimeImmutable($today))->modify("+{$soonDays} days")->format('Y-m-d');
        return $dueDate <= $soonLimit ? DueStatus::Soon : DueStatus::Ok;
    }
}
