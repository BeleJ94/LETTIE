<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Auth\AccountLockedException;
use App\Domain\Auth\LoginThrottle;
use App\Domain\Auth\PasswordPolicy;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private LoginThrottle $throttle;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->throttle = new LoginThrottle();
        $this->now = new DateTimeImmutable('2026-09-30 10:00:00');
    }

    /** @return list<DateTimeImmutable> */
    private function failures(int ...$minutesAgo): array
    {
        return array_map(fn (int $m): DateTimeImmutable => $this->now->modify("-{$m} minutes"), $minutesAgo);
    }

    public function testFourFailuresDoNotLock(): void
    {
        self::assertNull($this->throttle->lockedUntil($this->failures(1, 2, 3, 4), [], $this->now));
    }

    public function testFiveFailuresLockUntilOldestLeavesWindow(): void
    {
        $until = $this->throttle->lockedUntil($this->failures(0, 1, 2, 3, 10), [], $this->now);
        self::assertEquals($this->now->modify('+5 minutes'), $until);
    }

    public function testUsesFifthMostRecentRegardlessOfOrder(): void
    {
        $until = $this->throttle->lockedUntil($this->failures(10, 3, 0, 2, 1, 12), [], $this->now);
        self::assertEquals($this->now->modify('+5 minutes'), $until);
    }

    public function testExpiredLockIsNull(): void
    {
        self::assertNull($this->throttle->lockedUntil($this->failures(15, 15, 15, 15, 15), [], $this->now));
    }

    public function testIpLimit(): void
    {
        $ip = $this->failures(...array_fill(0, 19, 1));
        self::assertNull($this->throttle->lockedUntil([], $ip, $this->now));
        $ip[] = $this->now->modify('-2 minutes');
        self::assertEquals($this->now->modify('+13 minutes'), $this->throttle->lockedUntil([], $ip, $this->now));
    }

    public function testLatestOfAccountAndIpLockWins(): void
    {
        $until = $this->throttle->lockedUntil(
            $this->failures(1, 1, 1, 1, 10),
            $this->failures(...array_fill(0, 20, 2)),
            $this->now,
        );
        self::assertEquals($this->now->modify('+13 minutes'), $until);
    }

    public function testWindowStart(): void
    {
        self::assertEquals($this->now->modify('-15 minutes'), $this->throttle->windowStart($this->now));
    }

    public function testLockedExceptionMinutesRemaining(): void
    {
        $e = new AccountLockedException($this->now->modify('+61 seconds'));
        self::assertSame(2, $e->minutesRemaining($this->now));
        self::assertSame(1, (new AccountLockedException($this->now))->minutesRemaining($this->now));
    }

    public function testPasswordPolicy(): void
    {
        PasswordPolicy::assertAcceptable('correct horse battery');
        $this->expectException(DomainException::class);
        PasswordPolicy::assertAcceptable('short');
    }
}
