<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DomainException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    /** bcrypt ignores bytes beyond 72. */
    public const MAX_BYTES = 72;

    public static function assertAcceptable(string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new DomainException('Password must contain at least ' . self::MIN_LENGTH . ' characters.');
        }
        if (strlen($password) > self::MAX_BYTES) {
            throw new DomainException('Password must not exceed ' . self::MAX_BYTES . ' bytes.');
        }
    }
}
