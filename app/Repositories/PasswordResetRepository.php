<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;

/**
 * password_resets is used before any user is signed in (like login_attempts): the scope is
 * required by the base class but no row filter applies. Build it with SiteScope::system().
 */
final class PasswordResetRepository extends Repository
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt, ?string $ip, DateTimeImmutable $at): void
    {
        $this->assertUnrestricted();
        $packed = $ip !== null && $ip !== '' ? @inet_pton($ip) : false;
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, created_at) VALUES (:user, :hash, :expires, :ip, :at)'
        );
        $stmt->execute([
            'user' => $userId,
            'hash' => $tokenHash,
            'expires' => $expiresAt->format(self::DATETIME_FORMAT),
            'ip' => $packed === false ? null : $packed,
            'at' => $at->format(self::DATETIME_FORMAT),
        ]);
    }

    /** User of a link that is still usable, or null. */
    public function findUserId(string $tokenHash, DateTimeImmutable $now): ?int
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now');
        $stmt->execute(['hash' => $tokenHash, 'now' => $now->format(self::DATETIME_FORMAT)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function countSince(int $userId, DateTimeImmutable $since): int
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :user AND created_at >= :since');
        $stmt->execute(['user' => $userId, 'since' => $since->format(self::DATETIME_FORMAT)]);
        return (int) $stmt->fetchColumn();
    }

    /** A password was chosen: every pending link of the account stops working. */
    public function markAllUsed(int $userId, DateTimeImmutable $at): void
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('UPDATE password_resets SET used_at = :at WHERE user_id = :user AND used_at IS NULL');
        $stmt->execute(['user' => $userId, 'at' => $at->format(self::DATETIME_FORMAT)]);
    }

    public function purgeExpiredBefore(DateTimeImmutable $before): int
    {
        $this->assertUnrestricted();
        $stmt = $this->pdo->prepare('DELETE FROM password_resets WHERE expires_at < :before');
        $stmt->execute(['before' => $before->format(self::DATETIME_FORMAT)]);
        return $stmt->rowCount();
    }
}
