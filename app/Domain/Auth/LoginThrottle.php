<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DateTimeImmutable;

/**
 * Lockout rule: 5 failed logins for an account (or 20 from one IP) within a
 * 15-minute window, not followed by a success, lock further attempts until the
 * oldest of those failures leaves the window.
 */
final class LoginThrottle
{
    public const MAX_FAILURES_PER_ACCOUNT = 5;
    public const MAX_FAILURES_PER_IP = 20;
    public const WINDOW_MINUTES = 15;

    public function windowStart(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('-' . self::WINDOW_MINUTES . ' minutes');
    }

    /**
     * @param list<DateTimeImmutable> $accountFailures failures inside the window, after the last success
     * @param list<DateTimeImmutable> $ipFailures failures from the client IP inside the window
     */
    public function lockedUntil(array $accountFailures, array $ipFailures, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $until = self::until($accountFailures, self::MAX_FAILURES_PER_ACCOUNT);
        $ipUntil = self::until($ipFailures, self::MAX_FAILURES_PER_IP);
        if ($ipUntil !== null && ($until === null || $ipUntil > $until)) {
            $until = $ipUntil;
        }
        return $until !== null && $until > $now ? $until : null;
    }

    /** @param list<DateTimeImmutable> $failures */
    private static function until(array $failures, int $max): ?DateTimeImmutable
    {
        if (count($failures) < $max) {
            return null;
        }
        usort($failures, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);
        return $failures[$max - 1]->modify('+' . self::WINDOW_MINUTES . ' minutes');
    }
}
