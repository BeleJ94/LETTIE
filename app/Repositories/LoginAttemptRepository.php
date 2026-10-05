<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;

/**
 * login_attempts is a global security table (not site-bound): the scope is
 * required by the base class but no row filter applies. Build it with
 * SiteScope::system().
 */
final class LoginAttemptRepository extends Repository
{
    public function record(string $email, ?string $ip, bool $succeeded, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, succeeded, attempted_at)
             VALUES (:email, :ip, :succeeded, :at)'
        );
        $stmt->execute([
            'email' => $email,
            'ip' => self::packIp($ip),
            'succeeded' => $succeeded ? 1 : 0,
            'at' => $at->format(self::DATETIME_FORMAT),
        ]);
    }

    /**
     * Failures for the account since $since and after its last successful login, most recent first.
     *
     * @return list<DateTimeImmutable>
     */
    public function recentFailuresForEmail(string $email, DateTimeImmutable $since, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE email = :email AND succeeded = 0 AND attempted_at >= :since
               AND attempted_at > COALESCE(
                   (SELECT MAX(s.attempted_at) FROM login_attempts s WHERE s.email = :email2 AND s.succeeded = 1),
                   \'1000-01-01\')
             ORDER BY attempted_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['email' => $email, 'email2' => $email, 'since' => $since->format(self::DATETIME_FORMAT)]);
        return self::toDates($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<DateTimeImmutable> */
    public function recentFailuresForIp(?string $ip, DateTimeImmutable $since, int $limit): array
    {
        $packed = self::packIp($ip);
        if ($packed === null) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at FROM login_attempts
             WHERE ip_address = :ip AND succeeded = 0 AND attempted_at >= :since
             ORDER BY attempted_at DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['ip' => $packed, 'since' => $since->format(self::DATETIME_FORMAT)]);
        return self::toDates($stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Last sign-in attempts of an account, most recent first (kept until the daily purge).
     *
     * @return list<array{at: DateTimeImmutable, succeeded: bool, ip: ?string}>
     */
    public function recentForEmail(string $email, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at, succeeded, ip_address FROM login_attempts WHERE email = :email ORDER BY attempted_at DESC, id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['email' => $email]);
        $utc = new DateTimeZone('UTC');
        return array_map(static function (array $r) use ($utc): array {
            $ip = $r['ip_address'] !== null ? @inet_ntop((string) $r['ip_address']) : false;
            return ['at' => new DateTimeImmutable((string) $r['attempted_at'], $utc), 'succeeded' => (bool) $r['succeeded'], 'ip' => $ip === false ? null : $ip];
        }, $stmt->fetchAll());
    }

    /** Unlocks an account: its recent failures no longer count. Returns the number of failures removed. */
    public function clearFailuresForEmail(string $email, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND succeeded = 0 AND attempted_at >= :since');
        $stmt->execute(['email' => $email, 'since' => $since->format(self::DATETIME_FORMAT)]);
        return $stmt->rowCount();
    }

    public function purgeOlderThan(DateTimeImmutable $before): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :before');
        $stmt->execute(['before' => $before->format(self::DATETIME_FORMAT)]);
        return $stmt->rowCount();
    }

    private static function packIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    /** @param array<int, mixed> $values
     *  @return list<DateTimeImmutable> */
    private static function toDates(array $values): array
    {
        $utc = new DateTimeZone('UTC');
        return array_map(static fn ($v): DateTimeImmutable => new DateTimeImmutable((string) $v, $utc), array_values($values));
    }
}
